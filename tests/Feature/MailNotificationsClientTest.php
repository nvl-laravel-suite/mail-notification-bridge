<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Nvl\MailNotificationBridge\MailNotificationsClient;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('the package client sends JSON with bearer authentication and the caller idempotency key', function () {
    $body = ['to' => [['email' => 'reader@example.com']], 'subject' => 'Receipt', 'html' => '<p>Paid</p>', 'css' => 'p { color: #333; }', 'text' => 'Paid', 'external_reference' => 'order-42'];
    $accepted = ['data' => ['id' => 'message-42', 'status' => 'accepted']];
    Http::fake(['mail.example.test/api/v1/messages' => Http::response($accepted, 202)]);
    $client = new MailNotificationsClient('https://mail.example.test/api/v1/', 'integration-secret');

    expect($client->send($body, 'order-42:receipt:v1'))->toBe($accepted);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://mail.example.test/api/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer integration-secret')
        && $request->hasHeader('Accept', 'application/json')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->hasHeader('Idempotency-Key', 'order-42:receipt:v1')
        && $request->data() === $body);
    Http::assertSentCount(1);
});

test('the package client throws a failed send and lets the caller replay the same key and payload', function () {
    $body = ['to' => [['email' => 'reader@example.com']], 'subject' => 'Receipt', 'text' => 'Paid'];
    Http::fake(['mail.example.test/api/v1/messages' => Http::sequence()
        ->push(['message' => 'Unavailable'], 503)
        ->push(['data' => ['id' => 'message-42']], 202)]);
    $client = new MailNotificationsClient('https://mail.example.test/api/v1', 'integration-secret');

    expect(fn () => $client->send($body, 'order-42:receipt:v1'))->toThrow(RequestException::class);
    Http::assertSentCount(1);
    expect($client->send($body, 'order-42:receipt:v1'))->toBe(['data' => ['id' => 'message-42']]);

    Http::assertSentCount(2);
    foreach (Http::recorded() as [$request]) {
        expect($request->header('Idempotency-Key'))->toBe(['order-42:receipt:v1']);
        expect($request->data())->toBe($body);
    }
});

test('the package client uploads a private catalog asset using file and name multipart fields', function () {
    Storage::fake('integration-example');
    Storage::disk('integration-example')->put('invoice.pdf', '%PDF invoice bytes');
    $parts = [];
    Http::fake(['mail.example.test/api/v1/assets' => function (Request $request) use (&$parts) {
        foreach ($request->data() as $part) {
            $parts[$part['name']] = ['contents' => (string) $part['contents'], 'filename' => $part['filename'] ?? null];
        }

        return Http::response(['data' => ['id' => 'asset-42', 'is_public' => false]], 201);
    }]);
    $client = new MailNotificationsClient('https://mail.example.test/api/v1', 'integration-secret');

    expect($client->uploadAsset(Storage::disk('integration-example')->path('invoice.pdf'), 'Invoice 42'))
        ->toBe(['data' => ['id' => 'asset-42', 'is_public' => false]]);

    expect($parts)->toBe([
        'name' => ['contents' => 'Invoice 42', 'filename' => null],
        'file' => ['contents' => '%PDF invoice bytes', 'filename' => 'invoice.pdf'],
    ]);
    Http::assertSentCount(1);
});

test('the package client forwards actual nested PHP field names for attachments and inline files', function () {
    Storage::fake('integration-example');
    Storage::disk('integration-example')->put('invoice.pdf', '%PDF invoice bytes');
    Storage::disk('integration-example')->put('logo.png', 'PNG logo bytes');
    $fields = ['to[0][email]' => 'reader@example.com', 'subject' => 'Receipt', 'html' => '<img src="cid:logo@example.com">', 'attachments[0][name]' => 'invoice.pdf', 'inline_assets[0][content_id]' => 'logo@example.com'];
    $parts = [];
    Http::fake(['mail.example.test/api/v1/messages' => function (Request $request) use (&$parts) {
        foreach ($request->data() as $part) {
            $parts[$part['name']] = (string) $part['contents'];
        }

        return Http::response(['data' => ['id' => 'multipart-message']], 202);
    }]);
    $client = new MailNotificationsClient('https://mail.example.test/api/v1', 'integration-secret');

    expect($client->sendMultipart($fields, [
        'attachments[0][file]' => Storage::disk('integration-example')->path('invoice.pdf'),
        'inline_assets[0][file]' => Storage::disk('integration-example')->path('logo.png'),
    ], 'order-42:receipt:v1'))->toBe(['data' => ['id' => 'multipart-message']]);

    expect($parts)->toBe([...$fields, 'attachments[0][file]' => '%PDF invoice bytes', 'inline_assets[0][file]' => 'PNG logo bytes']);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', 'order-42:receipt:v1')
        && $request->hasHeader('Authorization', 'Bearer integration-secret')
        && $request->hasHeader('Accept', 'application/json'));
});

test('the package client closes multipart files after an HTTP failure without automatically replaying them', function () {
    Storage::fake('integration-example');
    Storage::disk('integration-example')->put('invoice.pdf', '%PDF invoice bytes');
    $path = Storage::disk('integration-example')->path('invoice.pdf');
    $opened = [];
    Http::fake(['mail.example.test/api/v1/messages' => function () use ($path, &$opened) {
        foreach (get_resources('stream') as $stream) {
            if ((stream_get_meta_data($stream)['uri'] ?? null) === $path) {
                $opened[] = $stream;
            }
        }

        return Http::response(['message' => 'Unavailable'], 503);
    }]);
    $client = new MailNotificationsClient('https://mail.example.test/api/v1', 'integration-secret');

    expect(fn () => $client->sendMultipart(['to[0][email]' => 'reader@example.com', 'subject' => 'Receipt', 'text' => 'Paid'], ['attachments[0][file]' => $path], 'order-42:receipt:v1'))
        ->toThrow(RequestException::class);

    expect($opened)->not->toBeEmpty();
    foreach ($opened as $stream) {
        expect(is_resource($stream))->toBeFalse();
    }
    Http::assertSentCount(1);
});

test('the package client returns detail and paginated events and message summaries unchanged', function () {
    $detail = ['data' => ['id' => 'message-42', 'status' => 'submitted', 'html' => '<p>Paid</p>']];
    $page = ['data' => [['id' => 'event-2']], 'meta' => ['current_page' => 2, 'last_page' => 3, 'total' => 250]];
    $summaries = ['data' => [['id' => 'message-42', 'attachment_count' => 1]], 'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 25, 'total' => 26]];
    Http::fake([
        'mail.example.test/api/v1/messages/message-42/events*' => Http::response($page),
        'mail.example.test/api/v1/messages/message-42' => Http::response($detail),
        'mail.example.test/api/v1/messages?*' => Http::response($summaries),
    ]);
    $client = new MailNotificationsClient('https://mail.example.test/api/v1', 'integration-secret');

    expect($client->message('message-42'))->toBe($detail);
    expect($client->events('message-42', 2, 100))->toBe($page);
    expect($client->messages(['page' => 2, 'per_page' => 25, 'status' => 'submitted']))->toBe($summaries);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mail.example.test/api/v1/messages/message-42/events?page=2&per_page=100');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mail.example.test/api/v1/messages?page=2&per_page=25&status=submitted');
    Http::assertSentCount(3);
});

test('the package client refuses missing configuration before sending a request', function (string $baseUrl, string $token) {
    expect(fn () => new MailNotificationsClient($baseUrl, $token))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with([['', 'secret'], ['https://mail.example.test/api/v1', '']]);

test('invalid remote JSON envelopes fail explicitly without being returned as successful data', function (mixed $body) {
    Http::fake(['service.test/*' => Http::response($body, 200)]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'test-key');
    expect(fn () => $client->message('42'))->toThrow(RuntimeException::class, 'JSON object');
})->with(['null' => [null], 'invalid' => ['not-json'], 'number' => ['42'], 'list' => [['unkeyed']], 'empty list' => ['[]']]);

test('successful object envelopes retain nested arrays and allow an empty object', function (string $body, array $expected): void {
    Http::fake(['service.test/*' => Http::response($body, 200)]);

    expect((new MailNotificationsClient('https://service.test/api/v1', 'test-key'))->message('42'))->toBe($expected);
})->with([
    'empty object' => ['{}', []],
    'nested envelope' => ['{"data":{"id":"42","tags":["receipt"]},"meta":{}}', ['data' => ['id' => '42', 'tags' => ['receipt']], 'meta' => []]],
]);

test('redirects cannot be reported as successful API operations', function (string $method, array $arguments): void {
    Http::fake(['*' => Http::response(['data' => ['id' => 'pretend-success']], 302, ['Location' => 'https://untrusted.test/messages'])]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'test-key');

    expect(fn () => $client->{$method}(...$arguments))->toThrow(RuntimeException::class, 'successful response');
    Http::assertSentCount(1);
})->with([
    'read' => ['message', ['42']],
    'send' => ['send', [['subject' => 'Receipt', 'text' => 'Ready'], 'stable-key']],
    'remove asset' => ['archiveAsset', ['42']],
    'remove flag' => ['deleteFlag', ['42']],
    'remove opt-out' => ['deleteMarketingOptOut', ['42']],
    'remove webhook' => ['deleteWebhookEndpoint', ['42']],
    'revoke key' => ['revokeKey', ['42']],
]);

test('failed API responses retain their HTTP exception and status without replay', function (int $status): void {
    Http::fake(['service.test/*' => Http::response(['message' => 'Rejected'], $status)]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'test-key');

    try {
        $client->message('42');
        test()->fail('An unsuccessful API response was accepted.');
    } catch (RequestException $exception) {
        expect($exception->response->status())->toBe($status);
    }
    Http::assertSentCount(1);
})->with([401, 429, 503]);
