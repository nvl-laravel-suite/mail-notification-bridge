<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks\Http;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use JsonException;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Webhooks\SigningKey;
use Nvl\MailNotificationBridge\Webhooks\WebhookPayload;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhook
{
    public function __construct(private MailNotificationsManager $manager) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('nvl-mail-notification-bridge.webhooks.enabled', false)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $connection = $request->route('connection') ?? 'default';
        try {
            $connection = $this->manager->connectionName($connection);
            $profile = $this->manager->configuration($connection);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $body = $request->getContent();
        $maxBytes = filter_var(config('nvl-mail-notification-bridge.webhooks.max_bytes', 65536), FILTER_VALIDATE_INT);
        $maxBytes = max(1, min(1048576, $maxBytes === false ? 65536 : $maxBytes));
        $tolerance = filter_var(config('nvl-mail-notification-bridge.webhooks.tolerance', 300), FILTER_VALIDATE_INT);
        $tolerance = max(0, min(300, $tolerance === false ? 300 : $tolerance));
        if (strlen($body) > $maxBytes) {
            return response()->json(['message' => 'Webhook payload is too large.'], 413);
        }

        $id = $request->header('webhook-id', '');
        $timestamp = $request->header('webhook-timestamp', '');
        $signature = $request->header('webhook-signature', '');
        if (count($request->headers->all('webhook-id')) !== 1
            || count($request->headers->all('webhook-timestamp')) !== 1
            || count($request->headers->all('webhook-signature')) !== 1
            || ! WebhookPayload::isUuid($id) || ! preg_match('/^[0-9]{1,12}$/D', $timestamp)
            || strlen($signature) > 4096
            || abs(now()->getTimestamp() - (int) $timestamp) > $tolerance
            || ! $this->validSignature($id.'.'.$timestamp.'.'.$body, $signature, $profile['webhook_secrets'] ?? [])) {
            return $this->unauthorized();
        }

        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid webhook payload.'], 422);
        }
        if (! WebhookPayload::valid($payload)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 422);
        }
        if (! is_string($profile['client_id'] ?? null) || ! WebhookPayload::isUuid($profile['client_id'])
            || ! hash_equals($profile['client_id'], $payload['client_id']) || ! hash_equals($id, $payload['id'])) {
            return $this->unauthorized();
        }

        $request->attributes->set('nvl-mail-notification-bridge.webhook.connection', $connection);
        $request->attributes->set('nvl-mail-notification-bridge.webhook.id', $id);

        return $next($request);
    }

    private function validSignature(string $content, string $header, #[\SensitiveParameter] mixed $secrets): bool
    {
        if (! is_array($secrets) || count($secrets) > 10 || $secrets === []) {
            return false;
        }
        $valid = false;
        foreach ($secrets as $secret) {
            $key = SigningKey::decode($secret);
            if ($key === null) {
                continue;
            }
            $expected = hash_hmac('sha256', $content, $key, true);
            foreach (preg_split('/\s+/', trim($header)) ?: [] as $candidate) {
                if (! str_starts_with($candidate, 'v1,')) {
                    continue;
                }
                $decoded = base64_decode(substr($candidate, 3), true);
                if ($decoded !== false && hash_equals($expected, $decoded)) {
                    $valid = true;
                }
            }
        }

        return $valid;
    }

    private function unauthorized(): Response
    {
        return response()->json(['message' => 'Invalid webhook authentication.'], 401);
    }
}
