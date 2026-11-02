# Signed delivery callbacks

The engine receives provider webhooks and emits normalized client delivery callbacks. This bridge receives those **service callbacks**, not MailerSend/SendGrid/Mailgun webhooks. API tokens, service callback `whsec_` secrets and provider signing secrets are different credentials.

## Enable the optional receiver

Outbound-only installations need no database schema. For callbacks:

1. Set `webhooks.enabled` to true in the bridge config.
2. Configure a durable asynchronous queue, its existing jobs/backend storage, and `webhooks.queue`/`webhooks.connection`.
3. Publish and migrate the one optional receipt table, or set `migrations.enabled=true` to let the provider load it. Choose one migration owner.
4. Set the API profile's exact service UUID `client_id` and callback `webhook_secrets`.
   With the receiver enabled, `doctor` checks valid client IDs and signing keys on every configured API profile, including the resolved default. Remove unused profiles or configure their identities explicitly.
5. Register that profile's HTTPS URL as a callback endpoint in the service. Keep the returned signing secret private and configure it before delivery begins.

```bash
php artisan vendor:publish --tag=nvl-mail-notification-bridge-migrations
php artisan migrate
php artisan nvl:mail-notification-bridge:doctor
php artisan route:list --name=nvl-mail-notification-bridge.webhooks
```

The default POST route is `/mail-notifications/webhooks/{connection?}`. For `shop`, register `/mail-notifications/webhooks/shop`; the no-suffix route resolves the configured default API profile. The package installs `VerifyWebhook` middleware, not Bearer or browser-session authentication. It does not blanket-disable CSRF. If host global middleware applies CSRF or transaction wrappers, exempt only this exact machine endpoint: the receiver rejects an ambient receipt-database transaction rather than acknowledging uncommitted work. Rebuild route/config caches after enabling it.

`createWebhookEndpoint($attributes)` needs `webhooks:manage` and uses the service contract (`url`, `events`, `enabled`). Keep a bounded event allowlist. Never log the returned secret. Local HTTP loopback testing must be explicitly allowed by the **service's** outbound URL policy; use HTTPS in production. Provider sync and provider webhook setup remain service-side operations.

## Provision an endpoint without a signing-key race

Create it disabled, then store the returned secret in the consumer's secret manager/environment. The secret is returned at `data.secret` on creation/rotation; normal listings do not reveal it.

<!-- example:callback-registration -->
```php
use Nvl\MailNotificationBridge\MailNotificationsManager;

$registration = app(MailNotificationsManager::class)->connection('shop')
    ->createWebhookEndpoint([
        'url' => 'https://consumer.example.com/mail-notifications/webhooks/shop',
        'events' => ['message.submitted', 'message.delivered', 'message.failed'],
        'enabled' => false,
    ]);
$endpointId = $registration['data']['id'];
$signingSecret = $registration['data']['secret'];
```

After installing that secret under the matching `shop` profile, rebuilding caches and verifying the receiver/worker, call `updateWebhookEndpoint($endpointId, ['enabled' => true])` on the same client. Never print `$signingSecret` or expose it to browser code. `message.submitted` reports service/provider submission, not inbox delivery. Other supported examples include `message.sent`, `message.blocked`, `message.opened` and `message.clicked`; use the deployed service OpenAPI's event allowlist rather than inventing provider-specific names. The `'*'` subscription must be used alone if intentionally selecting all supported events.

## Storage and acknowledgement

The single initial alpha migration creates `nvl_mail_notification_bridge_webhook_receipts`, overridable through the documented `connection`/`tables` settings. Its unique identity is `(connection, event_id)`. `down()` drops the table and all receipts; batch rollback may also run unrelated migrations. Back up receipt data before destructive operations. Do not publish and auto-load duplicate migration copies. To retire the receiver, disable its service subscription, drain/reconcile pending receipts, back up the table, disable the route and stop its dedicated workers. Review `migrate:status` and the exact rollback batch before reversing the receipt migration; never roll back a shared batch blindly. Keep receipt archives until the replay/reconciliation window is closed.

Verification uses `webhook-id`, `webhook-timestamp` and `webhook-signature` over the **unmodified raw body** (`id.timestamp.body`), with the configured client binding and 300-second default tolerance. The default body limit is 64 KiB. Signature/client failures return 401, invalid event payloads 422, changed content for an existing identity 409. New durably committed receipts return 202, exact duplicates 200, and inability to commit returns 503. A 2xx receipt acknowledgement does not mean business listeners have completed.

After commit, the durable queue runs `ProcessWebhookReceipt`, which dispatches `MailNotificationReceived`. Queue outages retain recoverable pending receipts. Inline `sync`, `deferred`, `background` and `null` connections do not run business listeners as durable processing. See [operations](operations.md) for worker/lease settings and recovery.

## React to delivery events

Register your listener through normal Laravel event discovery or explicit `Event::listen(MailNotificationReceived::class, YourListener::class)`. Import `Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived`. It exposes `connection`, `eventId`, `payload`, `type()`, `messageId()` and `references()`.

Callbacks are **at least once** and may arrive out of order. Validate your own resource association and state transition; a signed callback does not authorize unrelated order mutations. Use a host-owned unique `(connection, eventId)` ledger in the same database transaction as a local business mutation. For external side effects, write an outbox transactionally and make the downstream operation idempotent. Do not set a processed flag before a nontransactional side effect and assume that prevents losses. If a queued listener is used, its retries/idempotency need independent configuration; receipt completion is not proof that separately queued listener work finished.

Do not reduce current delivery state solely to the last callback arrival. Use event time/type and inspect message state when reconciling. Only subscribe to supported service event types; exact payload fields/signatures are listed in the [PHP API](api.md) and service OpenAPI, not inferred from provider payloads.

## Rotation

Rotate the endpoint secret through the API or service dashboard, update the host secret allowlist, then remove the old key after the replay window/in-flight work is handled. The service signs future requests with its current secret immediately; coordinate the switch or pause endpoint delivery. The receiver supports up to ten valid `whsec_` keys for overlap. A signing key rotation is independent of API key rotation and client identity; never recreate the service client merely to rotate credentials.
