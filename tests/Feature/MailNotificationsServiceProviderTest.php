<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nvl\MailNotificationBridge\MailNotificationsClient;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('Composer discovery registers a lazy client using the consumers published configuration', function () {
    config(['nvl-mail-notification-bridge.base_url' => 'https://consumer.example.test/api/v1/', 'nvl-mail-notification-bridge.token' => 'consumer-secret']);
    Http::fake(['consumer.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    $client = app(MailNotificationsClient::class);

    expect($client->send(['subject' => 'Hello', 'text' => 'Ready', 'to' => [['email' => 'user@example.com']]], 'operation:42'))
        ->toBe(['data' => ['id' => 'accepted']]);

    expect(app(MailNotificationsClient::class))->toBe($client);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer consumer-secret'));
    Http::assertSentCount(1);
});

test('the configuration publishes with the documented Artisan tag', function () {
    $this->artisan('vendor:publish', ['--tag' => 'nvl-mail-notification-bridge-config', '--no-interaction' => true])->assertSuccessful();

    $published = require config_path('nvl-mail-notification-bridge.php');
    expect($published)->toHaveKeys(['base_url', 'token']);
    Http::assertNothingSent();
});

test('a cached configuration keeps the configured endpoint and credential', function () {
    config(['nvl-mail-notification-bridge.base_url' => 'https://cached.example.test/api/v1', 'nvl-mail-notification-bridge.token' => 'cached-secret']);
    file_put_contents(app()->getCachedConfigPath(), '<?php return '.var_export(config()->all(), true).';');
    $this->refreshApplication();
    Http::preventStrayRequests();
    Http::fake(['cached.example.test/api/v1/messages/42' => Http::response(['data' => ['id' => '42']])]);

    expect(app(MailNotificationsClient::class)->message('42'))->toBe(['data' => ['id' => '42']]);

    expect(app()->configurationIsCached())->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer cached-secret'));
});

test('an unconfigured consumer can boot but fails before making an API request', function () {
    config(['nvl-mail-notification-bridge.base_url' => null, 'nvl-mail-notification-bridge.token' => null]);

    expect(fn () => app(MailNotificationsClient::class))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
});
