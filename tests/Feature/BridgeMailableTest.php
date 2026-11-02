<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Nvl\MailNotificationBridge\Concerns\SubmitsMailNotifications;
use Nvl\MailNotificationBridge\Tests\Fixtures\OrderReceipt;

beforeEach(function () {
    app('view')->addLocation(__DIR__.'/../Fixtures/views');
    Http::preventStrayRequests();
});

test('a host Mailable retains recipients locale metadata and bounded attachments without SMTP', function (string $language, string $subject) {
    $mail = new OrderReceipt;
    $payload = $mail->toMailNotifications(['language' => $language, 'type' => 'transactional', 'category' => 'order.receipt', 'metadata' => ['order_id' => '42'], 'references' => [['type' => 'order', 'id' => '42']]]);

    expect($payload['subject'])->toBe($subject)
        ->and($payload['language'])->toBe($language)
        ->and($payload['html'])->toContain('lang="'.$language.'"')->toContain('Order 42')
        ->and($payload['to'][0]['email'])->toBe('recipient@example.test')
        ->and($payload['cc'][0]['email'])->toBe('copy@example.test')
        ->and($payload['bcc'][0]['email'])->toBe('private@example.test')
        ->and($payload['attachments'][0]['name'])->toBe('voucher.pdf')
        ->and(base64_decode($payload['attachments'][0]['content_base64']))->toBe('%PDF-1.4 bridge fixture')
        ->and($payload['inline_assets'][0]['content_id'])->not->toBeEmpty()
        ->and($payload['metadata'])->toBe(['order_id' => '42'])
        ->and($payload['references'])->toBe([['type' => 'order', 'id' => '42']])
        ->and($mail->to)->toBe([])
        ->and(app()->getLocale())->toBe('en')
        ->and(Mail::mailer()->getSymfonyTransport()->messages())->toHaveCount(0);
    Http::assertNothingSent();
})->with([['en', 'Order 42'], ['bg', 'Поръчка 42']]);

test('repeated rendering keeps generated content IDs stable for the same idempotent send', function () {
    $mail = new OrderReceipt;
    expect($mail->toMailNotifications())->toBe($mail->toMailNotifications());
});

test('the Mailable trait submits to the explicit API connection with the original idempotency key', function () {
    config(['nvl-mail-notification-bridge.connections.orders' => ['base_url' => 'https://orders.example.test/api/v1', 'token' => 'orders-secret']]);
    Http::fake(['orders.example.test/api/v1/messages' => Http::response(['data' => ['id' => 'mail-42']], 202)]);

    expect((new OrderReceipt)->submitMailNotifications('order:42:receipt', ['language' => 'bg'], 'orders'))->toBe(['data' => ['id' => 'mail-42']]);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer orders-secret')
        && $request->header('Idempotency-Key') === ['order:42:receipt'] && $request['language'] === 'bg'
        && $request['attachments'][0]['name'] === 'voucher.pdf');
    Http::assertSentCount(1);
});

test('Mailable options cannot replace service-owned sender routing or rendered content', function (array $options) {
    expect(fn () => (new OrderReceipt)->toMailNotifications($options))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([[['client_id' => 'foreign-client']], [['from' => 'spoof@example.test']], [['to' => [['email' => 'other@example.test']]]], [['html' => '<p>Override</p>']], [['language' => []]]]);

test('oversized Mailable attachments fail before HTTP submission', function () {
    $mail = (new OrderReceipt)->attachData(str_repeat('x', 10 * 1024 * 1024 + 1), 'too-large.pdf');
    expect(fn () => $mail->submitMailNotifications('oversized:1'))->toThrow(ValidationException::class);
    Http::assertNothingSent();
});

test('Laravel locale identifiers are normalized for the delivery API without changing render locale', function (string $locale, string $expected, bool $fromOptions) {
    $mail = new OrderReceipt;
    if (! $fromOptions) {
        $mail->locale($locale);
    }
    $payload = $mail->toMailNotifications($fromOptions ? ['language' => $locale] : []);
    expect($payload['language'])->toBe($expected)
        ->and($payload['html'])->toContain('lang="'.$locale.'"')
        ->and(app()->getLocale())->toBe('en');
})->with([['bg_BG', 'bg-bg', true], ['en_US', 'en-us', true], ['bg_BG', 'bg-bg', false], ['en_US', 'en-us', false]]);

test('modern Laravel envelopes content and attachment objects retain supported delivery fields', function () {
    $mail = new class extends Mailable
    {
        use SubmitsMailNotifications;

        public function envelope(): Envelope
        {
            return new Envelope(
                to: [new Address('modern@example.test', 'Modern recipient')],
                subject: 'Modern receipt', tags: ['receipt'], metadata: ['order' => '42'],
            );
        }

        public function content(): Content
        {
            return new Content(view: 'receipt', with: ['orderId' => '42']);
        }

        public function attachments(): array
        {
            return [Attachment::fromData(fn (): string => '%PDF modern', 'modern.pdf')->withMime('application/pdf')];
        }
    };
    $payload = $mail->toMailNotifications(['language' => 'bg']);
    expect($payload['to'])->toBe([['email' => 'modern@example.test', 'name' => 'Modern recipient']])
        ->and($payload['subject'])->toBe('Modern receipt')
        ->and($payload['html'])->toContain('Благодарим за поръчката')
        ->and($payload['tags'])->toBe(['receipt'])
        ->and($payload['metadata'])->toBe(['order' => '42'])
        ->and($payload['attachments'][0]['name'])->toBe('modern.pdf')
        ->and(base64_decode($payload['attachments'][0]['content_base64']))->toBe('%PDF modern');
    Http::assertNothingSent();
});
