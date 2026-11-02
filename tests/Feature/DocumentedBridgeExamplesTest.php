<?php

declare(strict_types=1);

use App\Mail\OrderReceiptMail;
use App\Models\PurchaseOrder;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Tests\Fixtures\User;

/** Read the actual published recipe rather than maintaining a second test-only copy. */
function bridgeDocumentationRecipe(string $guide, string $example): string
{
    $contents = file_get_contents(__DIR__.'/../../docs/'.$guide.'.md');
    if ($contents === false || preg_match('/<!-- example:'.preg_quote($example, '/').' -->\s*```php\s*\n(.*?)\n```/s', $contents, $match) !== 1) {
        throw new RuntimeException('Missing executable bridge recipe: '.$example);
    }

    return $match[1];
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    config([
        'nvl-mail-notification-bridge.default' => 'shop',
        'nvl-mail-notification-bridge.connections.shop' => ['base_url' => 'https://shop.example.test/api/v1', 'token' => 'shop-client-key'],
        'nvl-mail-notification-bridge.connections.other' => ['base_url' => 'https://other.example.test/api/v1', 'token' => 'other-client-key'],
    ]);
    Http::fake(['shop.example.test/api/v1/*' => Http::response(['data' => ['id' => 'accepted-message'], 'meta' => ['current_page' => 1, 'last_page' => 1]], 202)]);
});

test('the documented raw HTML CSS request keeps its complete service contract', function (): void {
    eval(bridgeDocumentationRecipe('getting-started', 'raw-send'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://shop.example.test/api/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer shop-client-key')
        && $request->hasHeader('Idempotency-Key', 'order:42:receipt:en:v1')
        && $request['css'] === '.receipt { color: #243b53; }'
        && $request['type'] === 'transactional' && $request['language'] === 'en'
        && $request['metadata'] === ['order_id' => '42']
        && $request['references'] === [['type' => 'order', 'id' => '42']]);
    Http::assertSentCount(1);
});

test('the documented bilingual Mailable prepares private files once and reuses its exact HTTP retry intent', function (string $language, string $subject, string $body): void {
    if (! class_exists(OrderReceiptMail::class)) {
        eval(bridgeDocumentationRecipe('laravel', 'mailable'));
    }
    foreach (['en' => 'english', 'bg' => 'bulgarian'] as $locale => $example) {
        $lines = eval(bridgeDocumentationRecipe('laravel', $example));
        foreach ($lines as $key => $value) {
            app('translator')->addLines(['bridge-mail.'.$key => $value], $locale);
        }
    }
    app()->setLocale('en');
    $path = tempnam(sys_get_temp_dir(), 'bridge-receipt-');
    if ($path === false) {
        throw new RuntimeException('Could not create private receipt fixture.');
    }
    $pdf = '%PDF-1.4 private bilingual receipt';
    file_put_contents($path, $pdf);
    try {
        $mail = new OrderReceiptMail('42', 'recipient@example.test', $path);
        $payload = $mail->toMailNotifications(['language' => $language, 'type' => 'transactional', 'references' => [['type' => 'order', 'id' => '42']]]);
        $key = 'order:42:receipt:'.$language.':v1';
        $client = app(MailNotificationsManager::class)->connection('shop');
        $client->send($payload, $key);
        $client->send($payload, $key);
        expect($payload['subject'])->toBe($subject)
            ->and($payload['html'])->toContain('lang="'.$language.'"')->toContain($body)
            ->and($payload['to'][0]['email'])->toBe('recipient@example.test')
            ->and($payload['attachments'][0]['name'])->toBe('receipt.pdf')
            ->and(base64_decode($payload['attachments'][0]['content_base64'], true))->toBe($pdf)
            ->and($payload['references'])->toBe([['type' => 'order', 'id' => '42']])
            ->and($mail->to)->toBe([])
            ->and(app()->getLocale())->toBe('en')
            ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->data() === $payload && $request->hasHeader('Idempotency-Key', $key));
        $requests = Http::recorded();
        expect($requests[0][0]->data())->toBe($requests[1][0]->data());
    } finally {
        unlink($path);
    }
})->with([['en', 'Order 42', 'Thank you for order 42.'], ['bg', 'Поръчка 42', 'Благодарим за поръчка 42.']]);

test('the documented notification routes users orders and arbitrary emails while preserving explicit client and references', function (string $kind, string $email, ?string $automaticType): void {
    if (! class_exists(PurchaseOrder::class)) {
        eval(bridgeDocumentationRecipe('laravel', 'order-model'));
    }
    config(['nvl-mail-notification-bridge.reference_types' => ['user' => User::class, 'order' => PurchaseOrder::class]]);
    $user = (new User)->forceFill(['id' => 7, 'email' => 'user@example.test']);
    $user->exists = true;
    $order = (new PurchaseOrder)->forceFill(['id' => 42, 'customer_email' => 'order@example.test', 'customer_name' => 'Customer']);
    $order->exists = true;
    eval(bridgeDocumentationRecipe('laravel', 'user-notification'));
    eval(bridgeDocumentationRecipe('laravel', 'arbitrary-recipient'));
    $target = match ($kind) {
        'user' => $user,
        'order' => $order,
        default => $recipient,
    };
    $target->notifyNow($notification);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://shop.example.test/api/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer shop-client-key')
        && $request['to'][0]['email'] === $email
        && in_array(['type' => 'order', 'id' => '42'], $request['references'], true)
        && ($automaticType !== 'user' || in_array(['type' => 'user', 'id' => '7'], $request['references'], true))
        && ($automaticType === 'user' || count($request['references']) === 1));
    Http::assertSentCount(1);
})->with([['user', 'user@example.test', 'user'], ['order', 'order@example.test', 'order'], ['guest', 'guest@example.test', null]]);

test('the documented history recipe retains immutable filters inclusive exclusive bounds and API pagination', function (): void {
    eval(bridgeDocumentationRecipe('history', 'history'));
    $base->status('failed');
    $base->paginate(page: 2, perPage: 25);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request['reference_type'] === 'order' && $request['reference_id'] === '42'
        && $request['statuses'] === ['sent', 'delivered'] && ! isset($request['status'])
        && $request['language'] === 'bg' && $request['created_from'] === '2026-11-01T00:00:00+00:00'
        && $request['created_to'] === '2026-11-02T00:00:00+00:00' && (int) $request['page'] === 2);
    expect($page['data']['id'])->toBe('accepted-message')->and($page['meta']['last_page'])->toBe(1);
});

test('the documented callback provisioning leaves delivery disabled until its returned secret is installed', function (): void {
    Http::swap(new Factory);
    Http::preventStrayRequests();
    $secret = 'whsec_'.base64_encode(str_repeat('x', 32));
    Http::fake(['shop.example.test/api/v1/webhook-endpoints' => Http::response(['data' => ['id' => 'endpoint-42', 'secret' => $secret]], 201)]);
    eval(bridgeDocumentationRecipe('callbacks', 'callback-registration'));
    expect($endpointId)->toBe('endpoint-42')->and($signingSecret)->toBe($secret);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['url'] === 'https://consumer.example.com/mail-notifications/webhooks/shop'
        && $request['events'] === ['message.submitted', 'message.delivered', 'message.failed'] && $request['enabled'] === false);
    Http::assertSentCount(1);
});
