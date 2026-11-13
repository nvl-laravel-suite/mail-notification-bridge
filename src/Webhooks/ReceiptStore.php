<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Nvl\MailNotificationBridge\Definitions\Tables\MailNotificationBridgeTables;
use Nvl\MailNotificationBridge\Webhooks\Jobs\ProcessWebhookReceipt;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Events\ConnectionCommitCallbacks;
use Throwable;

class ReceiptStore
{
    public const string TABLE = MailNotificationBridgeTables::WebhookReceipts;

    public const int MAX_ATTEMPTS = 5;

    public const int LEASE_SECONDS = 180;

    public function __construct(private DatabaseManager $database, private Dispatcher $bus, private Repository $config) {}

    /** Resolve the consumer-configured callback inbox table. */
    public static function table(): string
    {
        return PackageStorage::table('mail-notification-bridge', 'webhook_receipts', self::TABLE);
    }

    public function accept(string $connection, string $eventId, string $body): bool
    {
        return $this->database->connection(PackageStorage::connection('mail-notification-bridge'))->transaction(function () use ($connection, $eventId, $body): bool {
            $created = $this->receipts()->insertOrIgnore([
                'connection' => $connection, 'event_id' => $eventId, 'payload' => $body, 'payload_hash' => hash('sha256', $body),
                'status' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
            $receipt = $this->receipt($connection, $eventId)->first();
            if ($receipt === null) {
                throw new \RuntimeException('Webhook receipt could not be persisted.');
            }
            if (! is_string($receipt->payload_hash) || ! hash_equals($receipt->payload_hash, hash('sha256', $body))) {
                throw new ReceiptConflict;
            }
            $inboxConnection = $this->database->connection(PackageStorage::connection('mail-notification-bridge'));
            if (app()->bound(ConnectionCommitCallbacks::class)) {
                app(ConnectionCommitCallbacks::class)->afterCommit($inboxConnection, function () use ($connection, $eventId): void {
                    $this->enqueue($connection, $eventId);
                });
            } else {
                $inboxConnection->afterCommit(fn () => $this->enqueue($connection, $eventId));
            }

            return $created;
        });
    }

    public function usesDurableQueue(): bool
    {
        $connection = $this->config->get('nvl-mail-notification-bridge.webhooks.connection') ?: $this->config->get('queue.default');
        if (! is_string($connection) || $connection === '') {
            return false;
        }
        $driver = $this->config->get('queue.connections.'.$connection.'.driver');

        return in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }

    public function enqueue(string $connection, string $eventId): bool
    {
        if (! $this->usesDurableQueue()) {
            return false;
        }
        $claimed = $this->eligible($this->receipt($connection, $eventId))->update([
            'enqueue_until' => now()->addSeconds(self::LEASE_SECONDS), 'updated_at' => now(),
        ]);
        if ($claimed === 0) {
            return false;
        }
        try {
            $queueConnection = $this->config->get('nvl-mail-notification-bridge.webhooks.connection');
            $queue = $this->config->get('nvl-mail-notification-bridge.webhooks.queue', 'default');
            if (($queueConnection !== null && ! is_string($queueConnection)) || ! is_string($queue)) {
                throw new \InvalidArgumentException('Webhook queue settings must be strings or a null queue connection.');
            }
            $job = (new ProcessWebhookReceipt($connection, $eventId))
                ->onConnection($queueConnection)
                ->onQueue($queue);
            $this->bus->dispatch($job);
        } catch (Throwable $exception) {
            $this->receipt($connection, $eventId)->update(['enqueue_until' => null]);
            report($exception);

            return false;
        }

        return true;
    }

    public function recover(int $limit = 100): int
    {
        $this->receipts()->where('status', 'processing')->where('lease_until', '<=', now())
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->update(['status' => 'failed', 'lease_until' => null, 'lease_token' => null, 'enqueue_until' => null, 'updated_at' => now()]);
        $recovered = 0;
        $receipts = $this->eligible($this->receipts())->orderBy('id')->limit($limit)->get(['connection', 'event_id']);
        foreach ($receipts as $receipt) {
            if (! is_string($receipt->connection) || ! is_string($receipt->event_id)) {
                throw new \RuntimeException('Invalid callback receipt identity.');
            }
            if ($this->enqueue($receipt->connection, $receipt->event_id)) {
                $recovered++;
            }
        }

        return $recovered;
    }

    public function receipt(string $connection, string $eventId): Builder
    {
        return $this->receipts()->where('connection', $connection)->where('event_id', $eventId);
    }

    private function receipts(): Builder
    {
        return $this->database->connection(PackageStorage::connection('mail-notification-bridge'))->table(self::table());
    }

    private function eligible(Builder $query): Builder
    {
        return $query->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $query) => $query->whereNull('enqueue_until')->orWhere('enqueue_until', '<=', now()))
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('status', 'pending')
                        ->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
                })->orWhere(function (Builder $query): void {
                    $query->where('status', 'processing')->where('lease_until', '<=', now());
                });
            });
    }
}
