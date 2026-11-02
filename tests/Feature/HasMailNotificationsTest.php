<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Http;
use Nvl\MailNotificationBridge\Concerns\HasMailNotifications;
use Nvl\MailNotificationBridge\MailNotificationsClient;
use Nvl\MailNotificationBridge\Notifications\MailServiceNotification;
use Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['nvl-mail-notification-bridge.base_url' => 'https://service.test/api/v1', 'nvl-mail-notification-bridge.token' => 'key']);
});

function mailNotificationOrder(bool $saved = true): Model
{
    $model = new class extends Model
    {
        use HasMailNotifications;
        use Notifiable;

        public function routeNotificationForMailNotifications(): string
        {
            return 'customer@example.com';
        }
    };
    $model->setKeyType('string');
    $model->setAttribute('id', 42);
    $model->exists = $saved;

    return $model;
}

test('saved models expose explicit remote queries without querying during construction or serialization', function () {
    $order = mailNotificationOrder();
    config(['nvl-mail-notification-bridge.reference_types' => ['order' => $order::class]]);
    expect($order->mailNotificationReference())->toBe(['type' => 'order', 'id' => '42']);
    $query = $order->mailNotifications()->status('delivered');
    $order->toArray();
    Http::assertNothingSent();
    Http::fake(['*' => Http::response(['data' => [['id' => 'remote-message']]])]);

    expect($query->paginate()['data'])->toBe([['id' => 'remote-message']]);
    expect(Http::recorded()[0][0]->data())->toBe(['reference_type' => 'order', 'reference_id' => '42', 'status' => 'delivered', 'page' => 1, 'per_page' => 25]);
});

test('forModel uses the same stable local reference as the trait query', function () {
    $order = mailNotificationOrder();
    config(['nvl-mail-notification-bridge.reference_types' => ['order' => $order::class]]);
    Http::fake(['*' => Http::response(['data' => []])]);
    app(MailNotificationsClient::class)->query()->forModel($order)->paginate();
    expect(Http::recorded()[0][0]->data()['reference_id'])->toBe('42');
});

test('model classes can explicitly declare a stable business alias without a morph map', function () {
    $model = new class extends Model
    {
        use HasMailNotifications;

        public function mailNotificationReferenceType(): ?string
        {
            return 'invoice';
        }
    };
    $model->setKeyType('string');
    $model->setAttribute('id', 'invoice-123');
    $model->exists = true;
    expect($model->mailNotificationReference())->toBe(['type' => 'invoice', 'id' => 'invoice-123']);
    Http::assertNothingSent();
});

test('unsafe unsaved and unmapped model references fail local queries without network activity', function (string $case) {
    $order = mailNotificationOrder($case !== 'unsaved');
    config(['nvl-mail-notification-bridge.reference_types' => $case === 'unmapped' ? [] : ['order' => $order::class]]);
    if ($case === 'missing key') {
        $order->setAttribute('id', null);
    }
    if ($case === 'unsafe id') {
        $order->setAttribute('id', "42\n");
    }
    expect(fn () => $order->mailNotifications())->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['unsaved', 'unmapped', 'missing key', 'unsafe id']);

test('the channel appends a saved associated model reference while preserving explicit references', function () {
    $order = mailNotificationOrder();
    config(['nvl-mail-notification-bridge.reference_types' => ['order' => $order::class]]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'message']], 202)]);
    $order->notifyNow(new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Ready', 'text' => 'Ready',
        'references' => [['type' => 'user', 'id' => '17']]], 'order:42:ready')));
    expect(Http::recorded()[0][0]->data()['references'])->toBe([['type' => 'user', 'id' => '17'], ['type' => 'order', 'id' => '42']]);
});

test('automatic references deduplicate and omit unsafe model associations without suppressing delivery', function (string $case) {
    $order = mailNotificationOrder($case !== 'unsaved');
    config(['nvl-mail-notification-bridge.reference_types' => $case === 'unmapped' ? [] : ['order' => $order::class]]);
    Http::fake(['*' => Http::response(['data' => ['id' => 'message']], 202)]);
    $references = $case === 'duplicate' ? [['type' => 'order', 'id' => '42']] : [['type' => 'user', 'id' => '17']];
    $order->notifyNow(new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Ready', 'text' => 'Ready', 'references' => $references], 'order:42:ready')));
    expect(Http::recorded()[0][0]->data()['references'])->toBe($references);
})->with(['duplicate', 'unsaved', 'unmapped']);
