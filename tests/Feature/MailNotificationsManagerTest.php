<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Nvl\MailNotificationBridge\MailNotificationsClient;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Notifications\MailServiceNotification;
use Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('named connections isolate credentials and keep the legacy default untouched', function () {
    config(['nvl-mail-notification-bridge.base_url' => 'https://default.test/api/v1', 'nvl-mail-notification-bridge.token' => 'default-key',
        'nvl-mail-notification-bridge.connections' => ['shop' => ['base_url' => 'https://shop.test/api/v1', 'token' => 'shop-key', 'client_id' => 'shop-id', 'webhook_secrets' => ['current', 'previous']]]]);
    Http::fake(['*' => Http::response(['data' => []])]);
    $manager = app(MailNotificationsManager::class);
    $shop = $manager->connection('shop');
    expect($manager->configuration('shop')['webhook_secrets'])->toBe(['current', 'previous']);
    expect($manager->connection('shop'))->toBe($shop);
    $shop->messages();
    app(MailNotificationsClient::class)->messages();
    $shop->messages();
    expect(Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Authorization'))->all())
        ->toBe([['Bearer shop-key'], ['Bearer default-key'], ['Bearer shop-key']]);
});

test('configured default names resolve without changing other connection objects', function () {
    config(['nvl-mail-notification-bridge.default' => 'shop', 'nvl-mail-notification-bridge.connections' => ['shop' => ['base_url' => 'https://shop.test/api/v1', 'token' => 'shop-key']]]);
    Http::fake(['*' => Http::response(['data' => []])]);
    app(MailNotificationsClient::class)->messages();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://shop.test/api/v1/messages' && $request->hasHeader('Authorization', 'Bearer shop-key'));
});

test('unknown connections fail without using default credentials', function () {
    config(['nvl-mail-notification-bridge.base_url' => 'https://default.test/api/v1', 'nvl-mail-notification-bridge.token' => 'default-key']);
    expect(fn () => app(MailNotificationsManager::class)->connection('unknown'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('queued messages serialize the explicit connection and retain the existing operation identity', function () {
    config(['nvl-mail-notification-bridge.base_url' => 'https://default.test/api/v1', 'nvl-mail-notification-bridge.token' => 'default-key',
        'nvl-mail-notification-bridge.connections' => ['shop' => ['base_url' => 'https://shop.test/api/v1', 'token' => 'shop-key']]]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    $message = unserialize(serialize(new MailNotificationsMessage(['subject' => 'Hello', 'text' => 'Body'], 'operation:42', 'shop')));
    Notification::route('mail-notifications', 'customer@example.com')->notifyNow(new MailServiceNotification($message));
    Notification::route('mail-notifications', 'customer@example.com')->notifyNow(new MailServiceNotification(new MailNotificationsMessage($message->payload, $message->idempotencyKey)));
    $requests = Http::recorded();
    expect($requests[0][0]->url())->toBe('https://shop.test/api/v1/messages');
    expect($requests[0][0]->header('Authorization'))->toBe(['Bearer shop-key']);
    expect($requests[0][0]->header('Idempotency-Key'))->toBe($requests[1][0]->header('Idempotency-Key'));
});

test('an explicit named connection works without any default API credentials', function () {
    config(['nvl-mail-notification-bridge.base_url' => null, 'nvl-mail-notification-bridge.token' => null,
        'nvl-mail-notification-bridge.connections' => ['shop' => ['base_url' => 'https://shop.test/api/v1', 'token' => 'shop-key']]]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    Notification::route('mail-notifications', 'customer@example.com')->notifyNow(new MailServiceNotification(
        new MailNotificationsMessage(['subject' => 'Hello', 'text' => 'Body'], 'operation:42', 'shop'),
    ));
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer shop-key'));
});

test('connection credentials and HTTP limits are snapshots rather than ambient configuration switches', function () {
    config(['nvl-mail-notification-bridge.connections' => ['shop' => ['base_url' => 'https://shop.test/api/v1', 'token' => 'original', 'connect_timeout' => 2, 'timeout' => 7]]]);
    $options = null;
    Http::fake(function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response(['data' => []]);
    });
    $manager = app(MailNotificationsManager::class);
    $client = $manager->connection('shop');
    config(['nvl-mail-notification-bridge.connections.shop.token' => 'changed']);
    $client->messages();

    expect($manager->configuration('shop')['token'])->toBe('original');
    expect($options['connect_timeout'])->toBe(2);
    expect($options['timeout'])->toBe(7);
    expect($options['allow_redirects'])->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer original'));
});

test('connection names resolve default aliases to the canonical configured profile', function () {
    config(['nvl-mail-notification-bridge.default' => 'shop', 'nvl-mail-notification-bridge.connections' => ['shop' => ['base_url' => 'https://shop.test/api/v1', 'token' => 'shop-key']]]);
    $manager = app(MailNotificationsManager::class);

    expect($manager->connectionName())->toBe('shop');
    expect($manager->connectionName('shop'))->toBe('shop');
    expect(fn () => $manager->connectionName('unknown'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('legacy top level credentials retain the default canonical connection name', function () {
    config(['nvl-mail-notification-bridge.base_url' => 'https://service.test/api/v1', 'nvl-mail-notification-bridge.token' => 'secret']);
    expect(app(MailNotificationsManager::class)->connectionName())->toBe('default');
    Http::assertNothingSent();
});
