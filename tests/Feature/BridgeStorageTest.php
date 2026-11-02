<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Nvl\MailNotificationBridge\Webhooks\Http\ReceiveWebhook;
use Nvl\MailNotificationBridge\Webhooks\Jobs\ProcessWebhookReceipt;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;

beforeEach(function () {
    config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
        'database.connections.bridge' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true],
        'nvl-mail-notification-bridge.connection' => 'bridge',
        'nvl-mail-notification-bridge.tables.webhook_receipts' => 'host_mail_callbacks',
        'queue.default' => 'database', 'queue.connections.database.driver' => 'database']);
    DB::purge('sqlite');
    DB::purge('bridge');
});

test('the single optional schema supports fresh install rollback and reapply on the configured connection', function () {
    $migration = require glob(__DIR__.'/../../database/migrations/webhooks/*create_schema.php')[0];
    $migration->up();
    expect(Schema::connection('bridge')->hasTable('host_mail_callbacks'))->toBeTrue()
        ->and(Schema::connection('sqlite')->hasTable('host_mail_callbacks'))->toBeFalse()
        ->and(Schema::connection('bridge')->getColumnListing('host_mail_callbacks'))->toContain('payload_hash', 'lease_token', 'event_id');
    $migration->down();
    expect(Schema::connection('bridge')->hasTable('host_mail_callbacks'))->toBeFalse();
    $migration->up();
    expect(Schema::connection('bridge')->hasTable('host_mail_callbacks'))->toBeTrue();
    $migration->down();
});

test('callback persistence waits for its actual outer transaction and rolls back without queued effects', function () {
    $migration = require glob(__DIR__.'/../../database/migrations/webhooks/*create_schema.php')[0];
    $migration->up();
    Queue::fake();
    $db = DB::connection('bridge');
    $store = app(ReceiptStore::class);
    $db->beginTransaction();
    $store->accept('orders', 'a0000000-0000-4000-8000-000000000001', '{"test":true}');
    Queue::assertNothingPushed();
    $db->rollBack();
    expect($store->receipt('orders', 'a0000000-0000-4000-8000-000000000001')->exists())->toBeFalse();
    Queue::assertNothingPushed();

    $db->beginTransaction();
    $store->accept('orders', 'a0000000-0000-4000-8000-000000000002', '{"test":true}');
    Queue::assertNothingPushed();
    $db->commit();
    Queue::assertPushed(ProcessWebhookReceipt::class, 1);
    expect($store->receipt('orders', 'a0000000-0000-4000-8000-000000000002')->exists())->toBeTrue();
});

test('normal SDK boot creates no delivery tables or callback routes', function () {
    expect(config('nvl-mail-notification-bridge.migrations.enabled'))->toBeFalse()
        ->and(config('nvl-mail-notification-bridge.webhooks.enabled'))->toBeFalse()
        ->and(app('router')->getRoutes()->getByName('nvl-mail-notification-bridge.webhooks'))->toBeNull()
        ->and(Schema::connection('sqlite')->getTableListing())->toBe([]);
});

test('HTTP acknowledgement checks the inbox connection rather than an unrelated host transaction', function () {
    $migration = require glob(__DIR__.'/../../database/migrations/webhooks/*create_schema.php')[0];
    $migration->up();
    Queue::fake();
    $request = Request::create('/callback', 'POST', content: '{"test":true}');
    $request->attributes->set('nvl-mail-notification-bridge.webhook.connection', 'orders');
    $request->attributes->set('nvl-mail-notification-bridge.webhook.id', 'a0000000-0000-4000-8000-000000000003');
    $controller = app(ReceiveWebhook::class);
    DB::connection('sqlite')->beginTransaction();
    try {
        expect($controller($request, app(ReceiptStore::class), app('db'))->getStatusCode())->toBe(202);
    } finally {
        DB::connection('sqlite')->rollBack();
    }
    DB::connection('bridge')->beginTransaction();
    try {
        expect($controller($request, app(ReceiptStore::class), app('db'))->getStatusCode())->toBe(503);
    } finally {
        DB::connection('bridge')->rollBack();
    }
});

test('the webhook receiver refuses calls that bypass signature middleware', function () {
    $request = Request::create('/callback', 'POST', content: '{}');
    $response = app(ReceiveWebhook::class)($request, app(ReceiptStore::class), app('db'));
    expect($response->getStatusCode())->toBe(401);
});
