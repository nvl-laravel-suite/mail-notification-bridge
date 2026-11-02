<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks;

use DateTimeImmutable;

/**
 * Validates the versioned delivery callback contract at trust boundaries.
 *
 * @phpstan-type Callback array{version:1,id:non-empty-string,client_id:non-empty-string,type:non-empty-string,occurred_at:string,data:array{message_id:non-empty-string,status:non-empty-string,recipient_id:non-empty-string|null,template_version_id:non-empty-string|null,composition_revision_id:non-empty-string|null,error_code:non-empty-string|null,references:list<array{type:non-empty-string,id:non-empty-string}>}}
 *
 * @internal
 */
class WebhookPayload
{
    /** @phpstan-assert-if-true non-empty-string $value */
    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $value) === 1;
    }

    /** @phpstan-assert-if-true Callback $payload */
    public static function valid(mixed $payload): bool
    {
        if (! is_array($payload) || ($payload['version'] ?? null) !== 1
            || ! self::isUuid($payload['id'] ?? null) || ! self::isUuid($payload['client_id'] ?? null)
            || ! is_string($payload['type'] ?? null) || strlen($payload['type']) > 100
            || ! preg_match('/^message\.[a-z][a-z0-9_.-]*$/D', $payload['type'])
            || ! self::validDate($payload['occurred_at'] ?? null)
            || ! is_array($data = $payload['data'] ?? null)
            || ! self::isUuid($data['message_id'] ?? null)
            || ! self::boundedString($data['status'] ?? null, 100)
            || ! is_array($data['references'] ?? null) || ! array_is_list($data['references']) || count($data['references']) > 100) {
            return false;
        }
        foreach (['recipient_id', 'template_version_id', 'composition_revision_id'] as $key) {
            if (! array_key_exists($key, $data) || ($data[$key] !== null && ! self::isUuid($data[$key]))) {
                return false;
            }
        }
        if (! array_key_exists('error_code', $data) || ($data['error_code'] !== null && ! self::boundedString($data['error_code'], 255))) {
            return false;
        }
        foreach ($data['references'] as $reference) {
            if (! is_array($reference) || ! self::boundedString($reference['type'] ?? null, 100) || ! self::boundedString($reference['id'] ?? null, 191)
                || preg_match('/\A[^\s\x00-\x1f\x7f](?:[^\x00-\x1f\x7f]*[^\s\x00-\x1f\x7f])?\z/uD', $reference['id']) !== 1) {
                return false;
            }
        }

        return true;
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function boundedString(mixed $value, int $max): bool
    {
        return is_string($value) && $value !== '' && mb_strlen($value, 'UTF-8') <= $max;
    }

    private static function validDate(mixed $value): bool
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return false;
        }
        try {
            new DateTimeImmutable($value);

            return DateTimeImmutable::getLastErrors() === false;
        } catch (\Exception) {
            return false;
        }
    }
}
