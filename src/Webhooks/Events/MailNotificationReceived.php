<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks\Events;

use Nvl\MailNotificationBridge\Webhooks\WebhookPayload;

/**
 * A validated delivery event. Listeners must deduplicate their own external effects by event ID.
 *
 * @phpstan-import-type Callback from \Nvl\MailNotificationBridge\Webhooks\WebhookPayload
 *
 * @api
 */
final readonly class MailNotificationReceived
{
    /** @var Callback */
    public array $payload;

    /** @param array<array-key, mixed> $payload */
    public function __construct(public string $connection, public string $eventId, array $payload)
    {
        if (! WebhookPayload::valid($payload)) {
            throw new \InvalidArgumentException('Invalid Mail Notifications callback event.');
        }
        $this->payload = $payload;
    }

    public function type(): string
    {
        return $this->payload['type'];
    }

    public function messageId(): string
    {
        return $this->payload['data']['message_id'];
    }

    /** @return list<array{type: string, id: string}> */
    public function references(): array
    {
        return $this->payload['data']['references'];
    }
}
