<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Nvl\MailNotificationBridge\MailNotificationsClient;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('asset and operational methods preserve API envelopes and encoded identifiers', function (string $method, array $arguments, string $verb, string $path, array $data) {
    Http::fake(['*' => Http::response(['data' => ['id' => 'result'], 'meta' => ['total' => 1]])]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');

    expect($client->{$method}(...$arguments))->toBe(['data' => ['id' => 'result'], 'meta' => ['total' => 1]]);

    Http::assertSent(fn (Request $request): bool => $request->method() === $verb
        && strtok($request->url(), '?') === 'https://service.test/api/v1'.$path
        && $request->data() === $data && $request->hasHeader('Authorization', 'Bearer secret'));
    Http::assertSentCount(1);
})->with([
    ['client', [], 'GET', '/client', []],
    ['updateClient', [['name' => 'Shop']], 'PATCH', '/client', ['name' => 'Shop']],
    ['configuration', [], 'GET', '/configuration', []],
    ['assets', [['page' => 2]], 'GET', '/assets', ['page' => 2]],
    ['asset', ['a'], 'GET', '/assets/a', []],
    ['publishAsset', ['a'], 'POST', '/assets/a/publish', []],
    ['flags', [['page' => 2]], 'GET', '/flags', ['page' => 2]],
    ['flag', ['f'], 'GET', '/flags/f', []],
    ['createFlag', [['label' => 'Urgent']], 'POST', '/flags', ['label' => 'Urgent']],
    ['updateFlag', ['f', ['label' => 'Ready']], 'PATCH', '/flags/f', ['label' => 'Ready']],
    ['cancelMessage', ['m'], 'POST', '/messages/m/cancel', []],
    ['retryMessage', ['m'], 'POST', '/messages/m/retry', []],
    ['messageProvider', ['m'], 'GET', '/messages/m/provider', []],
]);

test('archive methods handle empty responses and surface remote failures', function (string $method, string $path) {
    Http::fake(['*' => Http::sequence()->push('', 204)->push(['message' => 'Forbidden'], 403)]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');

    expect($client->{$method}('a/b'))->toBeNull();
    expect(fn () => $client->{$method}('a/b'))->toThrow(RequestException::class);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://service.test/api/v1'.$path);
})->with([
    ['archiveAsset', '/assets/a%2Fb'],
    ['deleteFlag', '/flags/a%2Fb'],
]);

test('downloads use a caller owned writable stream without following redirects', function (string $method, array $arguments, string $path) {
    Http::fake(['*' => Http::response('binary-file', 200)]);
    $stream = fopen('php://temp', 'w+b');
    try {
        (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->{$method}(...[...$arguments, $stream]);
        rewind($stream);
        expect(stream_get_contents($stream))->toBe('binary-file');
        expect(is_resource($stream))->toBeTrue();
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://service.test/api/v1'.$path);
    } finally {
        fclose($stream);
    }
})->with([
    ['downloadAsset', ['a/b'], '/assets/a%2Fb/download'],
    ['downloadMessageAttachment', ['m', 'a/b'], '/messages/m/attachments/a%2Fb/download'],
]);

test('failed downloads throw and invalid output streams fail locally', function () {
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');
    $stream = fopen('php://temp', 'w+b');
    try {
        expect(fn () => $client->downloadAsset('asset', $stream))->toThrow(RequestException::class);
        expect(fn () => $client->downloadAsset('asset', '/tmp/unrequested-path'))->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($stream);
    }
    Http::assertSentCount(1);
});

test('webhook endpoint management preserves one time secrets and delivery filters', function (string $method, array $arguments, string $verb, string $path, array $data) {
    Http::fake(['*' => Http::response(['data' => ['id' => 'endpoint', 'secret' => 'one-time-secret']])]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');
    expect($client->{$method}(...$arguments))->toBe(['data' => ['id' => 'endpoint', 'secret' => 'one-time-secret']]);
    Http::assertSent(fn (Request $request): bool => $request->method() === $verb
        && strtok($request->url(), '?') === 'https://service.test/api/v1'.$path && $request->data() === $data);
})->with([
    ['webhookEndpoints', [['page' => 2]], 'GET', '/webhook-endpoints', ['page' => 2]],
    ['webhookEndpoint', ['a/b'], 'GET', '/webhook-endpoints/a%2Fb', []],
    ['createWebhookEndpoint', [['url' => 'https://receiver.test/webhooks', 'events' => ['*']]], 'POST', '/webhook-endpoints', ['url' => 'https://receiver.test/webhooks', 'events' => ['*']]],
    ['updateWebhookEndpoint', ['a', ['enabled' => false]], 'PATCH', '/webhook-endpoints/a', ['enabled' => false]],
    ['rotateWebhookSecret', ['a'], 'POST', '/webhook-endpoints/a/rotate-secret', []],
    ['webhookDeliveries', ['a', ['status' => 'failed', 'page' => 2]], 'GET', '/webhook-endpoints/a/deliveries', ['status' => 'failed', 'page' => 2]],
]);

test('webhook endpoint removal supports no content responses', function () {
    Http::fake(['*' => Http::response('', 204)]);
    expect((new MailNotificationsClient('https://service.test/api/v1', 'secret'))->deleteWebhookEndpoint('a/b'))->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://service.test/api/v1/webhook-endpoints/a%2Fb');
});

test('API key wrappers preserve the one time issuance envelope', function (string $method, array $arguments, string $verb, string $path, array $data) {
    Http::fake(['*' => Http::response(['data' => ['id' => 'key', 'token' => 'one-time-token']])]);
    expect((new MailNotificationsClient('https://service.test/api/v1', 'secret'))->{$method}(...$arguments))
        ->toBe(['data' => ['id' => 'key', 'token' => 'one-time-token']]);
    Http::assertSent(fn (Request $request): bool => $request->method() === $verb
        && strtok($request->url(), '?') === 'https://service.test/api/v1'.$path && $request->data() === $data);
})->with([
    ['keys', [['page' => 2]], 'GET', '/keys', ['page' => 2]],
    ['createKey', [['name' => 'worker', 'abilities' => ['messages:read']]], 'POST', '/keys', ['name' => 'worker', 'abilities' => ['messages:read']]],
]);

test('API key revocation handles an empty response', function () {
    Http::fake(['*' => Http::response('', 204)]);
    expect((new MailNotificationsClient('https://service.test/api/v1', 'secret'))->revokeKey('a/b'))->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://service.test/api/v1/keys/a%2Fb');
});

test('redirected downloads fail without forwarding credentials or writing a redirect body', function () {
    Http::fake(['*' => Http::response('Redirect', 302, ['Location' => 'https://untrusted.test/file'])]);
    $stream = fopen('php://temp', 'w+b');
    try {
        expect(fn () => (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->downloadAsset('a', $stream))->toThrow(RuntimeException::class);
        expect(ftell($stream))->toBe(0);
        Http::assertSentCount(1);
    } finally {
        fclose($stream);
    }
});

test('downloads release their response stream while retaining the callers destination', function () {
    $body = Utils::streamFor('binary-file');
    Http::fake(['*' => Http::response($body)]);
    $destination = fopen('php://temp', 'w+b');
    try {
        (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->downloadAsset('a', $destination);
        expect($body->isReadable())->toBeFalse();
        expect(is_resource($destination))->toBeTrue();
    } finally {
        fclose($destination);
    }
});

test('failed downloads release their response body without writing error data', function (int $status, string $exception) {
    $body = Utils::streamFor('error-body');
    Http::fake(['*' => Http::response($body, $status)]);
    $destination = fopen('php://temp', 'w+b');
    try {
        expect(fn () => (new MailNotificationsClient('https://service.test/api/v1', 'secret'))->downloadAsset('a', $destination))->toThrow($exception);
        expect($body->isReadable())->toBeFalse();
        expect(ftell($destination))->toBe(0);
    } finally {
        fclose($destination);
    }
})->with([[302, RuntimeException::class], [403, RequestException::class]]);

test('marketing opt out methods preserve envelopes filters and server validation failures', function () {
    $record = ['id' => 'opt-out-id', 'email' => 'customer@example.com', 'created_at' => '2026-11-02T12:00:00Z', 'updated_at' => '2026-11-02T12:00:00Z'];
    $page = ['data' => [$record], 'meta' => ['current_page' => 2, 'last_page' => 2, 'per_page' => 10, 'total' => 11]];
    Http::fake(['service.test/api/v1/marketing-opt-outs*' => Http::sequence()
        ->push($page)->push(['data' => $record])->push(['message' => 'Invalid email', 'errors' => ['email' => ['Invalid email']]], 422)]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');

    expect($client->marketingOptOuts(['email' => 'customer@example.com', 'page' => 2, 'per_page' => 10]))->toBe($page);
    expect($client->putMarketingOptOut('customer@example.com'))->toBe(['data' => $record]);
    expect(fn () => $client->putMarketingOptOut('invalid'))->toThrow(RequestException::class);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($requests[0]->method())->toBe('GET');
    expect($requests[0]->data())->toBe(['email' => 'customer@example.com', 'page' => 2, 'per_page' => 10]);
    expect($requests[1]->method())->toBe('PUT');
    expect($requests[1]->data())->toBe(['email' => 'customer@example.com']);
    expect($requests[1]->header('Authorization'))->toBe(['Bearer secret']);
    Http::assertSentCount(3);
});

test('marketing opt out release encodes the identifier and propagates tenant not found failures', function () {
    Http::fake(['service.test/api/v1/marketing-opt-outs/a%2Fb' => Http::sequence()->push('', 204)->push(['message' => 'Not found'], 404)]);
    $client = new MailNotificationsClient('https://service.test/api/v1', 'secret');

    expect($client->deleteMarketingOptOut('a/b'))->toBeNull();
    expect(fn () => $client->deleteMarketingOptOut('a/b'))->toThrow(RequestException::class);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://service.test/api/v1/marketing-opt-outs/a%2Fb');
    Http::assertSentCount(2);
});
