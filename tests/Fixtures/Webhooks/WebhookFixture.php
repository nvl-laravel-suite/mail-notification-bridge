<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Tests\Fixtures\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Nvl\MailNotificationBridge\Tests\TestCase;

class WebhookFixture
{
    public const string CLIENT_ID = 'a0000000-0000-4000-8000-000000000001';

    public const string EVENT_ID = 'a0000000-0000-4000-8000-000000000002';

    public const string MESSAGE_ID = 'a0000000-0000-4000-8000-000000000003';

    public const string SECRET = 'whsec_c3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3Nzc3M=';

    /** @return array<string, mixed> */
    public static function payload(): array
    {
        return ['version' => 1, 'id' => self::EVENT_ID, 'type' => 'message.delivered', 'occurred_at' => '2026-11-01T11:59:00Z', 'client_id' => self::CLIENT_ID, 'data' => ['message_id' => self::MESSAGE_ID, 'status' => 'delivered', 'references' => [['type' => 'invoice', 'id' => 'inv-42']], 'recipient_id' => null, 'template_version_id' => null, 'composition_revision_id' => null, 'error_code' => null]];
    }

    public static function body(): string
    {
        return json_encode(self::payload(), JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    public static function headers(string $body, ?int $timestamp = null, string $eventId = self::EVENT_ID): array
    {
        $timestamp ??= now()->timestamp;
        $signature = base64_encode(hash_hmac('sha256', $eventId.'.'.$timestamp.'.'.$body, base64_decode(substr(self::SECRET, 6)), true));

        return ['webhook-id' => $eventId, 'webhook-timestamp' => (string) $timestamp, 'webhook-signature' => 'v1,'.$signature, 'Content-Type' => 'application/json', 'Accept' => 'application/json'];
    }

    /** @param array<string, string>|null $headers
     * @return TestResponse<JsonResponse> */
    public static function post(TestCase $test, string $body, string $path = '/mail-notifications/webhooks', ?array $headers = null): TestResponse
    {
        $server = [];
        foreach ($headers ?? self::headers($body) as $key => $value) {
            $name = strtoupper(str_replace('-', '_', $key));
            $server[in_array($name, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $name : 'HTTP_'.$name] = $value;
        }

        return $test->call('POST', $path, server: $server, content: $body);
    }
}
