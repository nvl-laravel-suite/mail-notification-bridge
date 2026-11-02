<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks;

class SigningKey
{
    public static function decode(mixed $secret): ?string
    {
        if (! is_string($secret) || ! str_starts_with($secret, 'whsec_') || strlen($secret) > 256) {
            return null;
        }
        $key = base64_decode(substr($secret, 6), true);

        return $key !== false && strlen($key) >= 16 && strlen($key) <= 128 ? $key : null;
    }
}
