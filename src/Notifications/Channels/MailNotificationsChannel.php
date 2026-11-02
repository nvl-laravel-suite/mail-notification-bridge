<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Notifications\Channels;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use Nvl\MailNotificationBridge\Concerns\HasMailNotifications;
use Nvl\MailNotificationBridge\MailNotificationsClient;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage;

/** Laravel notifications routed to the remote delivery service.
 * @api
 */
class MailNotificationsChannel
{
    public function __construct(private ?MailNotificationsClient $client = null) {}

    /** @return array<string, mixed>|null */
    public function send(object $notifiable, Notification $notification): ?array
    {
        if (! method_exists($notifiable, 'routeNotificationFor')) {
            throw new InvalidArgumentException('The recipient must support Laravel notification routing.');
        }
        $route = $notifiable->routeNotificationFor('mail-notifications', $notification)
            ?? $notifiable->routeNotificationFor('mail', $notification);
        if ($route === null || $route === false || $route === '' || $route === []) {
            return null;
        }
        $to = $this->recipients($route);
        if (! method_exists($notification, 'toMailNotifications')) {
            throw new InvalidArgumentException('The notification must implement toMailNotifications().');
        }
        $message = $notification->toMailNotifications($notifiable);
        if (! $message instanceof MailNotificationsMessage) {
            throw new InvalidArgumentException('toMailNotifications() must return a MailNotificationsMessage.');
        }
        $modelId = $notifiable instanceof Model ? $notifiable->getKey() : null;
        $identity = is_string($modelId) || is_int($modelId)
            ? ['model' => $notifiable::class, 'id' => (string) $modelId]
            : ['emails' => array_column($to, 'email')];
        $key = 'notification:'.hash('sha256', json_encode([$message->idempotencyKey, $identity], JSON_THROW_ON_ERROR));
        $payload = [...$message->payload, 'to' => $to];

        $payload = $this->withModelReference($notifiable, $payload);
        $connection = $message->connection ?? null;
        $client = $connection === null
            ? ($this->client ?? app(MailNotificationsClient::class))
            : app(MailNotificationsManager::class)->connection($connection);

        return $client->send($payload, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withModelReference(object $notifiable, array $payload): array
    {
        if (! $notifiable instanceof Model || ! in_array(HasMailNotifications::class, class_uses_recursive($notifiable), true)
            || ! method_exists($notifiable, 'mailNotificationReference')) {
            return $payload;
        }
        try {
            $reference = $notifiable->mailNotificationReference();
        } catch (InvalidArgumentException) {
            return $payload;
        }
        if (! is_array($reference) || ! is_string($reference['type'] ?? null) || ! is_string($reference['id'] ?? null)) {
            throw new InvalidArgumentException('The model returned an invalid mail reference.');
        }
        $references = $payload['references'] ?? [];
        if (! is_array($references)) {
            return $payload;
        }
        foreach ($references as $existing) {
            if (is_array($existing) && ($existing['type'] ?? null) === $reference['type']
                && (is_string($existing['id'] ?? null) || is_int($existing['id'] ?? null)) && (string) $existing['id'] === $reference['id']) {
                return $payload;
            }
        }
        $payload['references'] = [...$references, $reference];

        return $payload;
    }

    /** @return list<array{email: string, name?: string}> */
    private function recipients(mixed $route): array
    {
        if (is_string($route)) {
            $route = [$route];
        }
        if (! is_array($route)) {
            throw new InvalidArgumentException('Use an email string, a list of emails, or an email-to-name array.');
        }
        $to = [];
        foreach ($route as $key => $value) {
            $email = is_int($key) ? $value : $key;
            if (! is_string($email) || trim($email) === '' || (! is_int($key) && ! is_string($value) && $value !== null)) {
                throw new InvalidArgumentException('Use an email string, a list of emails, or an email-to-name array.');
            }
            $recipient = ['email' => mb_strtolower(trim($email))];
            if (! is_int($key) && is_string($value)) {
                $recipient['name'] = $value;
            }
            $to[] = $recipient;
        }
        usort($to, fn (array $left, array $right): int => strcmp($left['email'], $right['email']));

        return $to;
    }
}
