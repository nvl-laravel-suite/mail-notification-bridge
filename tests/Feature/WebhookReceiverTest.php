<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotificationBridge\Tests\Fixtures\Webhooks\WebhookFixture;
use Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived;
use Nvl\MailNotificationBridge\Webhooks\Jobs\ProcessWebhookReceipt;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;
use Nvl\MailNotificationBridge\Webhooks\WebhooksServiceProvider;

beforeEach(function () {
    app()->singleton(ExceptionHandler::class, Handler::class);
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
        'queue.default' => 'database',
        'queue.connections.database' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90],
        'nvl-mail-notification-bridge.client_id' => WebhookFixture::CLIENT_ID,
        'nvl-mail-notification-bridge.webhook_secrets' => [WebhookFixture::SECRET],
        'nvl-mail-notification-bridge.webhooks' => ['enabled' => true, 'path' => 'mail-notifications/webhooks/{connection?}', 'queue' => 'default', 'connection' => null, 'tolerance' => 300, 'max_bytes' => 65536],
    ]);
    DB::purge('sqlite');
    $this->travelTo('2026-11-01 12:00:00 UTC');
});

function bootWebhookReceiver(): void
{
    $migration = require glob(__DIR__.'/../../database/migrations/webhooks/*create_schema.php')[0];
    $migration->up();
    app()->register(WebhooksServiceProvider::class, true);
    // The provider may already have booted disabled through Composer discovery.
    if (! app('router')->getRoutes()->getByName('nvl-mail-notification-bridge.webhooks')) {
        require __DIR__.'/../../routes/webhooks.php';
    }
    app('router')->getRoutes()->refreshNameLookups();
}

test('the webhook route is disabled unless the consumer opts in', function () {
    config(['nvl-mail-notification-bridge.webhooks.enabled' => false]);
    app()->register(WebhooksServiceProvider::class, true);

    $this->postJson('/mail-notifications/webhooks')->assertNotFound();
});

test('a valid signature persists an immutable receipt and queues work without calling listeners', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    Event::fake([MailNotificationReceived::class]);
    $body = WebhookFixture::body();

    WebhookFixture::post($this, $body)->assertAccepted()->assertJson(['accepted' => true]);

    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['connection' => 'default', 'event_id' => WebhookFixture::EVENT_ID, 'payload_hash' => hash('sha256', $body), 'status' => 'pending']);
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
    Event::assertNotDispatched(MailNotificationReceived::class);
});

test('invalid authenticity returns 401 before durable writes', function (string $case) {
    bootWebhookReceiver();
    Queue::fake();
    $body = WebhookFixture::body();
    $headers = WebhookFixture::headers($body);
    if ($case === 'stale') {
        $headers = WebhookFixture::headers($body, timestamp: now()->timestamp - 301);
    } elseif ($case === 'future') {
        $headers = WebhookFixture::headers($body, timestamp: now()->timestamp + 301);
    } elseif ($case === 'tampered') {
        $body = str_replace('delivered', 'failed', $body);
    } elseif ($case === 'missing') {
        unset($headers['webhook-signature']);
    } elseif ($case === 'malformed-key') {
        config(['nvl-mail-notification-bridge.webhook_secrets' => ['whsec_invalid!']]);
    } elseif ($case === 'different-client') {
        config(['nvl-mail-notification-bridge.client_id' => 'a0000000-0000-4000-8000-000000000099']);
    } elseif ($case === 'missing-client') {
        config(['nvl-mail-notification-bridge.client_id' => null]);
    } elseif ($case === 'header-id') {
        $headers = WebhookFixture::headers($body, eventId: 'a0000000-0000-4000-8000-000000000099');
    } elseif ($case === 'oversized-header') {
        $headers['webhook-signature'] = str_repeat('a', 4097);
    }

    WebhookFixture::post($this, $body, headers: $headers)->assertUnauthorized()->assertJson(['message' => 'Invalid webhook authentication.']);

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 0);
    Queue::assertNothingPushed();
})->with(['stale', 'future', 'tampered', 'missing', 'malformed-key', 'different-client', 'missing-client', 'header-id', 'oversized-header']);

test('current and previous secrets support standard webhook signature rotation', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    config(['nvl-mail-notification-bridge.webhook_secrets' => ['whsec_'.base64_encode(str_repeat('n', 32)), WebhookFixture::SECRET]]);
    $body = WebhookFixture::body();
    $headers = WebhookFixture::headers($body);
    $headers['webhook-signature'] = 'v2,ignored v1,'.base64_encode(str_repeat('x', 32)).' '.$headers['webhook-signature'];

    WebhookFixture::post($this, $body, headers: $headers)->assertAccepted();

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 1);
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
});

test('unknown named connections return 404 and configured connections are isolated', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    config(['nvl-mail-notification-bridge.connections.partner' => ['client_id' => WebhookFixture::CLIENT_ID, 'webhook_secrets' => [WebhookFixture::SECRET]]]);
    $body = WebhookFixture::body();

    WebhookFixture::post($this, $body, path: '/mail-notifications/webhooks/missing')->assertNotFound();
    WebhookFixture::post($this, $body)->assertAccepted();
    WebhookFixture::post($this, $body, path: '/mail-notifications/webhooks/partner')->assertAccepted();

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 2);
    Queue::assertPushed(ProcessWebhookReceipt::class, 2);
});

test('duplicate event IDs acknowledge identical raw bytes and conflict on altered payloads', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    $body = WebhookFixture::body();

    WebhookFixture::post($this, $body)->assertAccepted();
    WebhookFixture::post($this, $body)->assertOk()->assertJson(['duplicate' => true]);
    WebhookFixture::post($this, str_replace('delivered', 'failed', $body))->assertConflict();

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 1);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['payload' => $body]);
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
});

test('signed invalid envelopes return 422 without writes', function (string $case) {
    bootWebhookReceiver();
    Queue::fake();
    $payload = WebhookFixture::payload();
    if ($case === 'version') {
        $payload['version'] = 2;
    } elseif ($case === 'type') {
        $payload['type'] = 'user.created';
    } elseif ($case === 'references') {
        $payload['data']['references'] = [['type' => 'invoice', 'id' => ['unexpected']]];
    } elseif ($case === 'uuid') {
        $payload['data']['message_id'] = 'not-a-uuid';
    } elseif ($case === 'timestamp') {
        $payload['occurred_at'] = 'tomorrow';
    } elseif ($case === 'required-nullable') {
        unset($payload['data']['recipient_id']);
    }
    $body = $case === 'json' ? '{invalid' : json_encode($payload, JSON_THROW_ON_ERROR);

    WebhookFixture::post($this, $body)->assertUnprocessable()->assertJson(['message' => 'Invalid webhook payload.']);

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 0);
    Queue::assertNothingPushed();
})->with(['version', 'type', 'references', 'uuid', 'timestamp', 'required-nullable', 'json']);

test('body limits return 413 before persistence', function () {
    bootWebhookReceiver();
    Queue::fake();
    config(['nvl-mail-notification-bridge.webhooks.max_bytes' => 10]);

    WebhookFixture::post($this, WebhookFixture::body())->assertStatus(413);

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 0);
    Queue::assertNothingPushed();
});

test('a sync consumer acknowledges durable pending work without running its listeners', function () {
    bootWebhookReceiver();
    Event::fake([MailNotificationReceived::class]);
    config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);

    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();

    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'pending', 'attempts' => 0]);
    Event::assertNotDispatched(MailNotificationReceived::class);
});

test('processing dispatches a Laravel event once for completed receipts with stable identity and accessors', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    $received = [];
    Event::listen(MailNotificationReceived::class, function (MailNotificationReceived $event) use (&$received): void {
        $received[] = [$event->connection, $event->eventId, $event->type(), $event->messageId(), $event->references()];
    });
    $job = new ProcessWebhookReceipt('default', WebhookFixture::EVENT_ID);

    app()->call([$job, 'handle']);
    app()->call([$job, 'handle']);

    expect($received)->toBe([['default', WebhookFixture::EVENT_ID, 'message.delivered', WebhookFixture::MESSAGE_ID, [['type' => 'invoice', 'id' => 'inv-42']]]]);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'completed', 'attempts' => 1]);
});

test('failed listeners retry with the same event ID and bounded durable attempts', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    $identities = [];
    Event::listen(MailNotificationReceived::class, function (MailNotificationReceived $event) use (&$identities): void {
        $identities[] = $event->eventId;
        throw new RuntimeException('Temporary listener failure');
    });
    $job = new ProcessWebhookReceipt('default', WebhookFixture::EVENT_ID);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'Temporary listener failure');
        $this->travel(1)->hours();
    }
    app()->call([$job, 'handle']);

    expect($identities)->toBe(array_fill(0, 5, WebhookFixture::EVENT_ID));
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'failed', 'attempts' => 5]);
});

test('recovery queues lost dispatches and expired processing leases while skipping active work', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);
    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    config(['queue.default' => 'database']);

    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();
    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);

    DB::table('nvl_mail_notification_bridge_webhook_receipts')->update(['status' => 'processing', 'lease_until' => now()->subSecond(), 'lease_token' => 'expired', 'enqueue_until' => null, 'attempts' => 1]);
    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();
    Queue::assertPushed(ProcessWebhookReceipt::class, 2);
    app()->call([new ProcessWebhookReceipt('default', WebhookFixture::EVENT_ID), 'handle']);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'completed', 'attempts' => 2]);
});

test('a rolled back receipt transaction leaves no receipt or queued work', function () {
    bootWebhookReceiver();
    Queue::fake();
    DB::beginTransaction();
    app(ReceiptStore::class)->accept('default', WebhookFixture::EVENT_ID, WebhookFixture::body());

    DB::rollBack();

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 0);
    Queue::assertNothingPushed();
});

test('the doctor command rejects a disabled SDK configuration without printing secrets', function () {
    app()->register(WebhooksServiceProvider::class, true);
    config(['nvl-mail-notification-bridge.base_url' => null, 'nvl-mail-notification-bridge.token' => 'hidden-token', 'nvl-mail-notification-bridge.webhooks.enabled' => false]);

    $this->artisan('nvl:mail-notification-bridge:doctor')->expectsOutputToContain('Base URL and token')->doesntExpectOutputToContain('hidden-token')->assertFailed();
});

test('the doctor command checks webhook configuration and accepts a ready consumer', function () {
    bootWebhookReceiver();
    Schema::create('jobs', fn (Blueprint $table) => $table->id());
    config(['nvl-mail-notification-bridge.base_url' => 'https://notifications.example.test/api/v1', 'nvl-mail-notification-bridge.token' => 'hidden-token']);

    $this->artisan('nvl:mail-notification-bridge:doctor')->expectsOutputToContain('Mail Notifications configuration is ready.')->doesntExpectOutputToContain('hidden-token')->assertSuccessful();
});

test('the doctor command fails for unsafe webhook setup', function (string $case) {
    bootWebhookReceiver();
    config(['nvl-mail-notification-bridge.base_url' => 'https://notifications.example.test/api/v1', 'nvl-mail-notification-bridge.token' => 'hidden-token']);
    if ($case === 'client') {
        config(['nvl-mail-notification-bridge.client_id' => null]);
    } elseif ($case === 'secret') {
        config(['nvl-mail-notification-bridge.webhook_secrets' => ['whsec_invalid!']]);
    } elseif ($case === 'table') {
        Schema::drop('nvl_mail_notification_bridge_webhook_receipts');
    } elseif ($case === 'queue') {
        config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);
    } elseif ($case === 'route') {
        app('router')->setRoutes(new RouteCollection);
    }

    $this->artisan('nvl:mail-notification-bridge:doctor')->doesntExpectOutputToContain('hidden-token')->assertFailed();
})->with(['client', 'secret', 'table', 'queue', 'route']);

test('recovery refuses inline queue drivers and invalid limits', function () {
    bootWebhookReceiver();
    config(['queue.default' => 'sync', 'queue.connections.sync' => ['driver' => 'sync']]);
    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->expectsOutputToContain('durable asynchronous')->assertFailed();
    config(['queue.default' => 'database']);

    $this->artisan('nvl:mail-notification-bridge:webhooks:recover', ['--limit' => 0])->assertFailed();
});

test('recovery reserves an expired worker only once and marks exhausted crashed workers failed', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    DB::table('nvl_mail_notification_bridge_webhook_receipts')->update(['status' => 'processing', 'lease_until' => now()->subSecond(), 'enqueue_until' => null, 'attempts' => 1]);

    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();
    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();
    Queue::assertPushed(ProcessWebhookReceipt::class, 2);

    DB::table('nvl_mail_notification_bridge_webhook_receipts')->update(['attempts' => 5]);
    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'failed', 'attempts' => 5]);
});

test('broker failure leaves an accepted receipt recoverable after the broker returns', function () {
    bootWebhookReceiver();
    // The real database queue has no jobs table: the receipt must still be committed.
    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'pending', 'enqueue_until' => null]);
    Queue::fake([ProcessWebhookReceipt::class]);

    $this->artisan('nvl:mail-notification-bridge:webhooks:recover')->assertSuccessful();

    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
});

test('receipt storage failure returns JSON 503 and does not acknowledge lost work', function () {
    bootWebhookReceiver();
    Schema::drop('nvl_mail_notification_bridge_webhook_receipts');
    Queue::fake();

    WebhookFixture::post($this, WebhookFixture::body())->assertStatus(503)->assertJson(['message' => 'Webhook receipt could not be committed.']);

    Queue::assertNothingPushed();
});

test('an active processing lease blocks competing jobs until the lease expires', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    Event::fake([MailNotificationReceived::class]);
    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    DB::table('nvl_mail_notification_bridge_webhook_receipts')->update(['status' => 'processing', 'lease_until' => now()->addMinute(), 'attempts' => 1]);

    app()->call([new ProcessWebhookReceipt('default', WebhookFixture::EVENT_ID), 'handle']);

    Event::assertNotDispatched(MailNotificationReceived::class);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'processing', 'attempts' => 1]);
});

test('the doctor command rejects a database queue without its jobs table', function () {
    bootWebhookReceiver();
    config(['nvl-mail-notification-bridge.base_url' => 'https://notifications.example.test/api/v1', 'nvl-mail-notification-bridge.token' => 'hidden-token']);

    $this->artisan('nvl:mail-notification-bridge:doctor')->expectsOutputToContain('queue jobs table')->assertFailed();
});

test('authenticated future message event types preserve opaque references without resolving models', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    Event::fake([MailNotificationReceived::class]);
    $payload = WebhookFixture::payload();
    $payload['type'] = 'message.future_state';
    $payload['data']['status'] = 'new_state';
    $payload['data']['references'] = [['type' => 'App\\Models\\Administrator', 'id' => 'opaque-value']];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    WebhookFixture::post($this, $body)->assertAccepted();

    app()->call([new ProcessWebhookReceipt('default', WebhookFixture::EVENT_ID), 'handle']);

    Event::assertDispatched(MailNotificationReceived::class, fn (MailNotificationReceived $event): bool => $event->type() === 'message.future_state' && $event->references() === [['type' => 'App\\Models\\Administrator', 'id' => 'opaque-value']]);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'completed']);
});

test('an HTTP request inside an existing transaction returns 503 without acknowledging uncommitted work', function () {
    bootWebhookReceiver();
    Queue::fake();
    DB::beginTransaction();

    WebhookFixture::post($this, WebhookFixture::body())->assertStatus(503);

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 0);
    DB::rollBack();
    Queue::assertNothingPushed();
});

test('a real database queue processes the committed receipt outside its HTTP request', function () {
    bootWebhookReceiver();
    Schema::create('jobs', function (Blueprint $table): void {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    $received = [];
    Event::listen(MailNotificationReceived::class, function (MailNotificationReceived $event) use (&$received): void {
        $received[] = $event->eventId;
    });

    WebhookFixture::post($this, WebhookFixture::body())->assertAccepted();
    expect($received)->toBe([]);
    $this->assertDatabaseCount('jobs', 1);
    $job = Queue::connection('database')->pop('default');
    expect($job)->not->toBeNull();
    $job->fire();
    $job->delete();

    expect($received)->toBe([WebhookFixture::EVENT_ID]);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['status' => 'completed']);
    $this->assertDatabaseCount('jobs', 0);
});

test('default and named webhook aliases share one canonical receipt and event dispatch', function () {
    bootWebhookReceiver();
    config([
        'nvl-mail-notification-bridge.default' => 'shop',
        'nvl-mail-notification-bridge.connections.shop' => ['client_id' => WebhookFixture::CLIENT_ID, 'webhook_secrets' => [WebhookFixture::SECRET]],
    ]);
    Queue::fake([ProcessWebhookReceipt::class]);
    Event::fake([MailNotificationReceived::class]);
    $body = WebhookFixture::body();

    WebhookFixture::post($this, $body)->assertAccepted();
    WebhookFixture::post($this, $body, path: '/mail-notifications/webhooks/shop')->assertOk()->assertJson(['duplicate' => true]);
    WebhookFixture::post($this, $body, path: '/mail-notifications/webhooks/default')->assertOk()->assertJson(['duplicate' => true]);

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 1);
    $this->assertDatabaseHas('nvl_mail_notification_bridge_webhook_receipts', ['connection' => 'shop']);
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
    app()->call([new ProcessWebhookReceipt('shop', WebhookFixture::EVENT_ID), 'handle']);
    Event::assertDispatched(MailNotificationReceived::class, fn (MailNotificationReceived $event): bool => $event->connection === 'shop' && $event->eventId === WebhookFixture::EVENT_ID);
    Event::assertDispatchedTimes(MailNotificationReceived::class, 1);
});

test('signed callbacks preserve and deduplicate 191 character Unicode reference IDs', function () {
    bootWebhookReceiver();
    Queue::fake([ProcessWebhookReceipt::class]);
    Event::fake([MailNotificationReceived::class]);
    $payload = WebhookFixture::payload();
    $referenceId = str_repeat('é', 191);
    $payload['data']['references'] = [['type' => 'invoice', 'id' => $referenceId]];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    WebhookFixture::post($this, $body)->assertAccepted();
    WebhookFixture::post($this, $body)->assertOk()->assertJson(['duplicate' => true]);
    app()->call([new ProcessWebhookReceipt('default', WebhookFixture::EVENT_ID), 'handle']);

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 1);
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
    Event::assertDispatched(MailNotificationReceived::class, fn (MailNotificationReceived $event): bool => $event->references() === [['type' => 'invoice', 'id' => $referenceId]]);
});

test('signed callbacks reject reference IDs outside the service character and whitespace contract', function (string $referenceId) {
    bootWebhookReceiver();
    Queue::fake();
    $payload = WebhookFixture::payload();
    $payload['data']['references'] = [['type' => 'invoice', 'id' => $referenceId]];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    WebhookFixture::post($this, $body)->assertUnprocessable();

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 0);
    Queue::assertNothingPushed();
})->with([str_repeat('é', 192), str_repeat('a', 192), ' leading', "trailing\t", "embedded\x00control", "embedded\nnewline"]);

test('an open transaction on an unrelated connection does not suppress webhook receipt dispatch on rollback', function () {
    bootWebhookReceiver();
    config([
        'database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:'],
    ]);
    Queue::fake([ProcessWebhookReceipt::class]);
    $body = WebhookFixture::body();

    DB::connection('unrelated')->beginTransaction();
    WebhookFixture::post($this, $body)->assertAccepted();
    DB::connection('unrelated')->rollBack();

    $this->assertDatabaseCount('nvl_mail_notification_bridge_webhook_receipts', 1);
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
});
