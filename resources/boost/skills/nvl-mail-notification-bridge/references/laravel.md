# Laravel content and notification integration

The host authorizes business access and renders its own views. The bridge transports prepared content; it does not send through local SMTP or install templates in the delivery service.

## A bilingual Mailable with a private attachment

Create this in `app/Mail/OrderReceiptMail.php`. Supply an already authorized recipient, order number and readable private attachment path. The example does not create or retrieve orders.

<!-- example:mailable -->
```php
namespace App\Mail;

use Illuminate\Mail\Mailable;
use Nvl\MailNotificationBridge\Concerns\SubmitsMailNotifications;

final class OrderReceiptMail extends Mailable
{
    use SubmitsMailNotifications;

    public function __construct(
        public string $orderNumber,
        public string $recipient,
        private string $privatePath,
    ) {}

    public function build(): static
    {
        return $this->to($this->recipient)
            ->subject(__('bridge-mail.subject', ['number' => $this->orderNumber]))
            ->html('<!doctype html><html lang="'.e(app()->getLocale()).'"><body><p>'
                .e(__('bridge-mail.body', ['number' => $this->orderNumber])).'</p></body></html>')
            ->attach($this->privatePath, ['as' => 'receipt.pdf', 'mime' => 'application/pdf']);
    }
}
```

`lang/en/bridge-mail.php`:

<!-- example:english -->
```php
return ['subject' => 'Order :number', 'body' => 'Thank you for order :number.'];
```

`lang/bg/bridge-mail.php`:

<!-- example:bulgarian -->
```php
return ['subject' => 'Поръчка :number', 'body' => 'Благодарим за поръчка :number.'];
```

Prepare once, then retain this payload with its stable operation key:

```php
use App\Mail\OrderReceiptMail;
use Nvl\MailNotificationBridge\MailNotificationsManager;

$payload = (new OrderReceiptMail('42', 'customer@example.test', $privatePath))
    ->toMailNotifications([
        'language' => 'bg',
        'type' => 'transactional',
        'category' => 'order.receipt',
        'references' => [['type' => 'order', 'id' => '42']],
    ]);
$accepted = app(MailNotificationsManager::class)->connection('shop')
    ->send($payload, 'order:42:receipt:bg:v1');
```

`language` selects the render locale and is forwarded to the service; Laravel's previous locale is restored. The trait clones the Mailable. It retains To/Cc/Bcc, subject, HTML, available plain text and supported attachments/inline images. From and Reply-To are service-owned. Files must be accessible at preparation time. The [operations guide](operations.md) covers size and supported-content limits.

For a single immediate submission, `submitMailNotifications($key, $options, 'shop')` prepares and submits in one call. For previews, durable retries or auditing, prefer prepare-once and `send()`; do not render a fresh timestamp or new content under the old key. A local preview of `$payload['html']` is the prepared host HTML. Service CSS inlining, sender resolution, testing interception and provider MIME construction can change the final delivery; inspect the service's stored message to verify those settings.

## Users

A normal Laravel `Notifiable` user routes through `mail` unless it supplies `routeNotificationForMailNotifications`. Add `HasMailNotifications` and the configured stable `user` alias from [history](history.md) for automatic saved-user references. Construct a queued notification after business authorization:

<!-- example:user-notification -->
```php
use Nvl\MailNotificationBridge\Notifications\MailServiceNotification;
use Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage;

$notification = new MailServiceNotification(new MailNotificationsMessage([
    'subject' => 'Order 42',
    'html' => '<p>Your receipt is ready.</p>',
    'type' => 'transactional',
    'language' => 'en',
    'references' => [['type' => 'order', 'id' => '42']],
], 'order:42:receipt:user:7:en:v1', 'shop'));
```

Call `$user->notify($notification)`. This built-in notification implements `ShouldQueue` and dispatches after commit. It has three attempts with backoff. Your custom notification may instead return `MailNotificationsChannel::class` from `via()` and return a `MailNotificationsMessage` from `toMailNotifications()`.

The message payload must **omit `to`**: the channel derives recipients from the notifiable. Use one logical key per intended notifiable send. Its service idempotency key also incorporates the notifiable identity. Skipped/null/false/empty recipient routes do not send. An unmapped or unsaved model does not acquire an automatic reference; configure aliases and use saved models, or pass explicit stable references when appropriate.

## Orders

Apply the same two traits to your existing order model and route it to its authorized customer. This example assumes the model already has `customer_email` and `customer_name` attributes; the package creates no business tables.

<!-- example:order-model -->
```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Nvl\MailNotificationBridge\Concerns\HasMailNotifications;

class PurchaseOrder extends Model
{
    use HasMailNotifications;
    use Notifiable;

    public function routeNotificationForMailNotifications(Notification $notification): array
    {
        return [$this->customer_email => $this->customer_name];
    }
}
```

Map `order` to `App\Models\PurchaseOrder::class`, create a `MailServiceNotification` as above with an order-specific logical key, then call `$order->notify($notification)`. The configured saved order reference is added automatically. A reference associates mail history with a resource; it does not authorize access or trigger order state transitions.

## Arbitrary email addresses

<!-- example:arbitrary-recipient -->
```php
use Illuminate\Support\Facades\Notification;

$recipient = Notification::route('mail-notifications', [
    'guest@example.test' => 'Guest',
]);
```

Call `$recipient->notify($notification)` with a message built as above, an appropriate new logical key, and explicit references if needed. There is no inferred user model for an arbitrary email. In tests or when intentionally synchronous, Laravel's `notifyNow($notification)` executes the channel immediately. Do not replace queued production delivery with it merely to avoid configuring workers.
