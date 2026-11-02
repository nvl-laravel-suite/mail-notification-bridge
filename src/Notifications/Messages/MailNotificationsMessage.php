<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Notifications\Messages;

use InvalidArgumentException;

/** Validated immutable notification content and stable delivery identity.
 * @api
 *
 * @phpstan-consistent-constructor
 */
readonly class MailNotificationsMessage
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload, public string $idempotencyKey, public ?string $connection = null)
    {
        if (! preg_match('/\A[\x21-\x7e]{1,191}\z/', $idempotencyKey)) {
            throw new InvalidArgumentException('Supply a durable logical operation key of 1–191 printable non-space ASCII characters.');
        }
        if (array_key_exists('to', $payload)) {
            throw new InvalidArgumentException('Notification recipients must come from the notifiable route, not the payload.');
        }
    }

    public function type(string $type): static
    {
        return $this->with(['type' => $type]);
    }

    public function category(?string $category): static
    {
        return $this->with(['category' => $category]);
    }

    public function language(?string $language): static
    {
        return $this->with(['language' => $language]);
    }

    /** @param array<array-key, mixed> $metadata */
    public function metadata(array $metadata): static
    {
        return $this->with(['metadata' => $metadata]);
    }

    /** @param list<string> $tags */
    public function tags(array $tags): static
    {
        return $this->with(['tags' => $tags]);
    }

    /** @param array<string, mixed> $payload */
    private function with(array $payload): static
    {
        return new static([...$this->payload, ...$payload], $this->idempotencyKey, $this->connection);
    }
}
