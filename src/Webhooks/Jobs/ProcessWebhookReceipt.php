<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;
use Throwable;

class ProcessWebhookReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public string $connectionName, public string $eventId) {}

    public function handle(ReceiptStore $receipts, Dispatcher $events): void
    {
        $token = (string) Str::uuid();
        $claimed = $receipts->receipt($this->connectionName, $this->eventId)
            ->where('attempts', '<', ReceiptStore::MAX_ATTEMPTS)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('status', 'pending')->where(fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()));
                })->orWhere(fn (Builder $query) => $query->where('status', 'processing')->where('lease_until', '<=', now()));
            })->increment('attempts', 1, ['status' => 'processing', 'lease_token' => $token, 'lease_until' => now()->addSeconds(ReceiptStore::LEASE_SECONDS), 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $receipt = $receipts->receipt($this->connectionName, $this->eventId)->where('lease_token', $token)->first();
        if ($receipt === null) {
            return;
        }
        $attempt = filter_var($receipt->attempts, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => ReceiptStore::MAX_ATTEMPTS]]);
        if ($attempt === false) {
            throw new \RuntimeException('Webhook receipt has an invalid attempt count.');
        }
        try {
            if (! is_string($receipt->payload)) {
                throw new \RuntimeException('Invalid persisted callback payload.');
            }
            $payload = json_decode($receipt->payload, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new \RuntimeException('Invalid persisted callback payload.');
            }
            $events->dispatch(new MailNotificationReceived($this->connectionName, $this->eventId, $payload));
            $receipts->receipt($this->connectionName, $this->eventId)->where('lease_token', $token)->update([
                'status' => 'completed', 'completed_at' => now(), 'lease_until' => null, 'lease_token' => null, 'enqueue_until' => null, 'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $receipts->receipt($this->connectionName, $this->eventId)->where('lease_token', $token)->update([
                'status' => $attempt >= ReceiptStore::MAX_ATTEMPTS ? 'failed' : 'pending',
                'next_attempt_at' => now()->addSeconds($this->backoff[$attempt - 1] ?? 900),
                'lease_until' => null, 'lease_token' => null, 'enqueue_until' => null, 'updated_at' => now(),
            ]);

            throw $exception;
        }
    }
}
