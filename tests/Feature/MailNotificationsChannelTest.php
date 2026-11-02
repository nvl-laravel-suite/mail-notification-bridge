<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Nvl\MailNotificationBridge\Notifications\Channels\MailNotificationsChannel;
use Nvl\MailNotificationBridge\Notifications\MailServiceNotification;
use Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage;
use Nvl\MailNotificationBridge\Tests\Fixtures\User;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['nvl-mail-notification-bridge.base_url' => 'https://mail.example.test/api/v1', 'nvl-mail-notification-bridge.token' => 'consumer-key']);
});

test('the Laravel channel submits a users notification and explicit business references through the client API', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted-message']], 202)]);
    $user = (new User)->forceFill(['id' => 17, 'email' => 'customer@example.com']);
    $payload = ['subject' => 'Receipt for order 42', 'html' => '<p>Благодарим</p>', 'css' => 'p { color: #333; }', 'text' => 'Благодарим',
        'references' => [['type' => 'order', 'id' => '42'], ['type' => 'user', 'id' => '17']], 'external_reference' => 'legacy-order-42'];

    $user->notifyNow(new MailServiceNotification(new MailNotificationsMessage($payload, 'order:42:receipt')));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://mail.example.test/api/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer consumer-key')
        && $request->data() === [...$payload, 'to' => [['email' => 'customer@example.com']]]);
    Http::assertSentCount(1);
});

test('an order can route a notification to its customer without a local user in the mail service', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'order-message']], 202)]);
    $order = new class extends Model
    {
        use Notifiable;

        public function routeNotificationForMailNotifications(LaravelNotification $notification): array
        {
            return ['customer@example.com' => 'Customer'];
        }
    };
    $order->setAttribute('id', 42);
    $payload = ['subject' => 'Order confirmed', 'text' => 'Thank you', 'references' => [['type' => 'order', 'id' => '42']]];

    $order->notifyNow(new MailServiceNotification(new MailNotificationsMessage($payload, 'order:42:confirmed')));

    Http::assertSent(fn (Request $request): bool => $request->data() === [...$payload, 'to' => [['email' => 'customer@example.com', 'name' => 'Customer']]]);
    Http::assertSentCount(1);
});

test('on demand notifications preserve prepared HTML CSS text and attachments', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'composition-message']], 202)]);
    $payload = ['subject' => 'Receipt for order 42', 'html' => '<p>Благодарим</p>',
        'css' => 'p { color: #333; }', 'text' => 'Благодарим',
        'attachments' => [['name' => 'voucher.pdf', 'content_base64' => base64_encode('%PDF sample')]], 'references' => [['type' => 'order', 'id' => '42']]];

    Notification::route('mail-notifications', ['gift@example.com' => 'Gift recipient'])
        ->notifyNow(new MailServiceNotification(new MailNotificationsMessage($payload, 'order:42:gift')));

    Http::assertSent(fn (Request $request): bool => $request->data() === [...$payload, 'to' => [['email' => 'gift@example.com', 'name' => 'Gift recipient']]]);
    Http::assertSentCount(1);
});

test('queued notification retries retain the delivery key payload and prepared content', function () {
    Queue::fake([SendQueuedNotifications::class]);
    Http::fake(['mail.example.test/api/v1/messages' => Http::sequence()->push(['message' => 'Unavailable'], 503)->push(['data' => ['id' => 'replayed']], 202)]);
    $user = Notification::route('mail-notifications', 'customer@example.com');
    $payload = ['subject' => 'Receipt for order 42', 'html' => '<p>Paid</p>', 'text' => 'Paid'];
    $user->notify(new MailServiceNotification(new MailNotificationsMessage($payload, 'order:42:receipt')));
    $job = Queue::pushed(SendQueuedNotifications::class)->first();
    expect($job->afterCommit)->toBeTrue();
    $firstAttempt = unserialize(serialize($job));

    expect(fn () => $firstAttempt->handle(app(ChannelManager::class)))->toThrow(RequestException::class);
    unserialize(serialize($job))->handle(app(ChannelManager::class));

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($requests)->toHaveCount(2);
    expect($requests[0]->header('Idempotency-Key'))->toBe($requests[1]->header('Idempotency-Key'));
    expect($requests[0]->data())->toBe([...$payload, 'to' => [['email' => 'customer@example.com']]])->toBe($requests[1]->data());
    Queue::assertPushed(SendQueuedNotifications::class, 1);
});

test('different users sharing a notification have separate stable delivery identities', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    $first = (new User)->forceFill(['id' => 17, 'email' => 'first@example.com']);
    $second = (new User)->forceFill(['id' => 18, 'email' => 'second@example.com']);
    $notification = new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], 'order:42:update'));

    Notification::sendNow([$first, $second], $notification);
    $first->notifyNow($notification);

    $keys = Http::recorded()->map(fn (array $pair): array => $pair[0]->header('Idempotency-Key'));
    expect($keys[0])->not->toBe($keys[1]);
    expect($keys[0])->toBe($keys[2]);
    Http::assertSentCount(3);
});

test('a changed users email cannot silently create a second delivery during a retry', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    $user = (new User)->forceFill(['id' => 17, 'email' => 'original@example.com']);
    $notification = new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], 'order:42:update'));
    $user->notifyNow($notification);
    $user->email = 'changed@example.com';
    $user->notifyNow($notification);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($requests[0]->header('Idempotency-Key'))->toBe($requests[1]->header('Idempotency-Key'));
    Http::assertSentCount(2);
});

test('notification routing does not require the consuming app to register polymorphic model aliases', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    $user = (new User)->forceFill(['id' => 17, 'email' => 'customer@example.com']);
    $previousMap = Relation::morphMap();
    $previousRequired = Relation::requiresMorphMap();
    Relation::enforceMorphMap([], false);
    try {
        $user->notifyNow(new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], 'user:17:update')));
    } finally {
        Relation::morphMap($previousMap, false);
        Relation::requireMorphMap($previousRequired);
    }

    Http::assertSentCount(1);
});

test('an empty custom route suppresses delivery instead of falling back to the users email', function () {
    $user = new class extends User
    {
        public function routeNotificationForMailNotifications(LaravelNotification $notification): array
        {
            return [];
        }
    };
    $user->email = 'fallback@example.com';

    $user->notifyNow(new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], 'suppressed-route')));

    Http::assertNothingSent();
});

test('arbitrary mail routes work without creating an entity reference or a user record', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted']], 202)]);

    Notification::route('mail', 'guest@example.com')->notifyNow(new MailServiceNotification(
        new MailNotificationsMessage(['subject' => 'Guest message', 'text' => 'Welcome'], 'guest:welcome:42'),
    ));

    Http::assertSent(fn (Request $request): bool => $request->data() === ['subject' => 'Guest message', 'text' => 'Welcome', 'to' => [['email' => 'guest@example.com']]]);
    Http::assertSentCount(1);
});

test('a notifiable without a delivery address does not submit a message', function () {
    $user = (new User)->forceFill(['email' => null]);

    $user->notifyNow(new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], 'no-address')));

    Http::assertNothingSent();
});

test('invalid notification routes fail before an HTTP request', function (mixed $route) {
    $notification = new MailServiceNotification(new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], 'invalid-route'));

    expect(fn () => Notification::route('mail-notifications', $route)->notifyNow($notification))->toThrow(InvalidArgumentException::class);

    Http::assertNothingSent();
})->with(['invalid type' => 42, 'empty address' => [['']], 'invalid name' => [['customer@example.com' => ['nested']]]]);

test('the channel rejects a notification without its payload contract', function () {
    $user = (new User)->forceFill(['email' => 'customer@example.com']);

    expect(fn () => app(MailNotificationsChannel::class)->send($user, new LaravelNotification))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('the notification channel rejects payload recipients that bypass notifiable routing', function () {
    expect(fn () => new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready', 'to' => [['email' => 'bypass@example.com']]], 'operation-42'))
        ->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('the notification channel requires an explicit durable logical operation key', function (string $key) {
    expect(fn () => new MailNotificationsMessage(['subject' => 'Update', 'text' => 'Ready'], $key))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
})->with(['missing' => '', 'line break' => "order\n42", 'too long' => str_repeat('a', 192)]);

test('queued message payloads serialized before named connections retain default delivery and idempotency', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'accepted']], 202)]);
    $payload = ['subject' => 'Receipt for order 42', 'html' => '<p>Paid</p>', 'text' => 'Paid'];
    $serialized = sprintf('O:%d:"%s":2:{s:7:"payload";%ss:14:"idempotencyKey";%s}',
        strlen(MailNotificationsMessage::class), MailNotificationsMessage::class, serialize($payload), serialize('order:42:receipt'));
    $legacyMessage = unserialize($serialized);
    $recipient = Notification::route('mail-notifications', 'customer@example.com');

    $recipient->notifyNow(new MailServiceNotification($legacyMessage));
    $recipient->notifyNow(new MailServiceNotification(new MailNotificationsMessage($payload, 'order:42:receipt')));

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($requests[0]->data())->toBe([...$payload, 'to' => [['email' => 'customer@example.com']]])->toBe($requests[1]->data());
    expect($requests[0]->header('Idempotency-Key'))->toBe($requests[1]->header('Idempotency-Key'));
    Http::assertSentCount(2);
});

test('notification classification helpers preserve raw payload and queued serialization', function () {
    Http::fake(['mail.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'classified-message']], 202)]);
    $payload = ['subject' => 'News', 'text' => 'Prepared news', 'data' => ['legacy' => 'unchanged'], 'references' => [['type' => 'order', 'id' => '42']]];
    $original = new MailNotificationsMessage($payload, 'newsletter:42', 'default');
    $classified = $original->type('marketing')->category('newsletter')->language('en-GB')
        ->metadata(['campaign_id' => 'autumn-42'])->tags(['autumn']);
    $restored = unserialize(serialize(new MailServiceNotification($classified)));
    Notification::route('mail-notifications', 'customer@example.com')->notifyNow($restored);

    expect($original->payload)->toBe($payload);
    expect($classified->idempotencyKey)->toBe('newsletter:42');
    expect($classified->connection)->toBe('default');
    Http::assertSent(fn (Request $request): bool => $request->data() === [...$payload,
        'type' => 'marketing', 'category' => 'newsletter', 'language' => 'en-GB', 'metadata' => ['campaign_id' => 'autumn-42'],
        'tags' => ['autumn'], 'to' => [['email' => 'customer@example.com']]]);
    Http::assertSentCount(1);
});

test('notification conveniences preserve inherited DTO wrappers and can clear nullable labels', function () {
    $original = new readonly class(['subject' => 'Receipt', 'text' => 'Prepared', 'category' => 'receipt', 'language' => 'bg'], 'receipt:42') extends MailNotificationsMessage {};
    $updated = $original->category(null)->language(null)->type('transactional');

    expect($updated::class)->toBe($original::class);
    expect($updated->payload)->toBe(['subject' => 'Receipt', 'text' => 'Prepared', 'category' => null, 'language' => null, 'type' => 'transactional']);
    expect($original->payload)->toBe(['subject' => 'Receipt', 'text' => 'Prepared', 'category' => 'receipt', 'language' => 'bg']);
    expect($updated->idempotencyKey)->toBe('receipt:42');
    expect($updated->connection)->toBeNull();
    Http::assertNothingSent();
});
