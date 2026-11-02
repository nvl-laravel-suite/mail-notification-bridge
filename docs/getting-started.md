# Setup and the HTTP boundary

## Configure a service client

Publish `nvl-mail-notification-bridge-config`. Set:

```dotenv
MAIL_NOTIFICATIONS_URL=https://mail.example.com/api/v1
MAIL_NOTIFICATIONS_TOKEN=replace-with-a-client-api-key
```

The URL includes the service API prefix, without `/messages`. A Bearer API key identifies the service client and its abilities. A request field or configured `client_id` cannot change this identity. Provider API credentials belong in the delivery service, never here. Do not expose API tokens or callback secrets in browser code or logs.

Sending needs `messages:send`; history needs `messages:read`; body inspection needs `messages:inspect`. Asset upload/download requires `assets:write`/`assets:read`; endpoint administration requires `webhooks:manage`. Grant only the abilities the host uses. Client suspension, quotas, provider suppressions and marketing opt-outs remain service decisions. Host policies decide whether the current actor may send to a person or access an order.

## Send prepared HTML and CSS

<!-- example:raw-send -->
```php
use Nvl\MailNotificationBridge\MailNotificationsClient;

$accepted = app(MailNotificationsClient::class)->send([
    'to' => [['email' => 'customer@example.test', 'name' => 'Customer']],
    'subject' => 'Your order 42',
    'html' => '<p class="receipt">Thank you for your order.</p>',
    'css' => '.receipt { color: #243b53; }',
    'text' => 'Thank you for your order.',
    'type' => 'transactional',
    'category' => 'order.receipt',
    'language' => 'en',
    'metadata' => ['order_id' => '42'],
    'tags' => ['receipt'],
    'references' => [['type' => 'order', 'id' => '42']],
], 'order:42:receipt:en:v1');
```

Replace the demonstration addresses and operation key with authorized, durable host data. API responses retain their `data`/`links`/`meta` envelopes. Acceptance is not delivery; inspect status/events or receive callbacks. Use `message($id)` and `events($id)` for client-scoped inspection. Sender and Reply-To settings are owned by the service. This is delivery-only: no template IDs, Blade execution or editor publication API is introduced by the bridge.

The key must identify one logical operation. Reuse the exact key **and prepared payload** after an uncertain network result; different content with an existing key is a conflict. Persist the payload/key in the host's delivery intent or outbox when recovery requires it. Never create a random retry key. A new business send gets a new key. Choose one submission path per operation: notification-channel keys are derived from the logical key and notifiable identity, while direct SDK/Mailable sends use the supplied key unchanged.

## Multiple clients

In the published config, replace `connections` with complete profiles:

```php
return [
'connections' => [
    'shop' => [
        'base_url' => env('SHOP_MAIL_URL'),
        'token' => env('SHOP_MAIL_TOKEN'),
        'client_id' => env('SHOP_MAIL_CLIENT_ID'),
        'webhook_secrets' => array_filter([env('SHOP_MAIL_WEBHOOK_SECRET')]),
    ],
],
];
```

Use `app(MailNotificationsManager::class)->connection('shop')`, importing `Nvl\MailNotificationBridge\MailNotificationsManager`. Set top-level `default` to `shop` to make it the injected default client. Named profiles inherit timeouts, **not** URL, token, client ID or signing secrets. Select the profile from trusted host tenancy configuration after authorization; never pass a request-supplied connection name straight through.

`client_id` is needed for callback client binding, not outbound Bearer authentication. Top-level `connection` means the receipt database connection; `connections` means API profiles; `webhooks.connection` means the queue connection. These are separate settings. Outbound-only installation needs no package tables or migrations. See [callbacks](callbacks.md) before enabling the optional receiver.

See [Laravel integration](laravel.md), [history](history.md), [operations](operations.md) and the [PHP API](../resources/boost/skills/nvl-mail-notification-bridge/references/api.md).
