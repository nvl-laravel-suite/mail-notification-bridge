# Business references and history

References are client-scoped external business identities, not polymorphic foreign keys into the service's database. The host owns models and authorization. The same `user:7` or `order:42` may exist under different service clients without sharing messages. Bearer credentials select the client; a reference cannot cross that boundary.

## Map stable aliases

Add `Nvl\MailNotificationBridge\Concerns\HasMailNotifications` to existing saved models. Configure `reference_types` in the published config:

```php
return [
'reference_types' => [
    'user' => App\Models\User::class,
    'order' => App\Models\PurchaseOrder::class,
],
];
```

Aliases are stable public identifiers (lowercase, bounded names), not PHP class names transmitted to the service. IDs are strings, at most 191 characters. Changing a class mapping should preserve its alias. No host morph map, service model, provider credential or business database replication is required. The trait rejects unmapped/unsaved models when explicitly asking for their reference/history.

`$user->mailNotificationReference()` returns `['type' => 'user', 'id' => '7']`. After authorizing access to that user, `$user->mailNotifications('shop')->paginate()` queries the remote service. Explicitly selecting a trusted connection is essential when the same model ID belongs to different tenants/clients.

## Query and paginate

<!-- example:history -->
```php
use Nvl\MailNotificationBridge\MailNotificationsManager;

$base = app(MailNotificationsManager::class)->connection('shop')->query()
    ->forReference('order', '42')
    ->type('transactional')
    ->language('bg')
    ->statuses(['sent', 'delivered'])
    ->createdBetween('2026-11-01T00:00:00Z', '2026-11-02T00:00:00Z');
$page = $base->paginate(page: 1, perPage: 25);
```

Time bounds are UTC-normalized, **from inclusive, to exclusive**. Prefer explicit absolute timestamps; `DateTimeInterface` is also supported. Inspect `data`, `links` and `meta` on the unchanged API envelope. Page numbers are bounded to 1–10,000 and page size to 1–100.

Query builders are immutable: retain the returned instance when adding a filter. `forReferences('order', ['42', '43'])` matches the listed IDs for one type; `forModel($order)` uses its configured trait reference. Additional methods include `forRecipient`, `status`, `ids`, `category` and `tag`. See the [PHP API](api.md) for exact signatures.

`$base->lazyAll(perPage: 100, maxPages: 10000)` traverses bounded pages. This is not an atomic historical snapshot while new messages arrive; use explicit time windows, retain processed message IDs, and respect API rate limits. A client key cannot enumerate another client's IDs or attachment bodies. Every host endpoint exposing this history still needs its own policy check.

## Classification is not consent

`type` distinguishes `transactional`, `marketing` and `notification`; `language`, `category`, `tags` and structured `metadata` describe the send. They do not grant permission to email somebody. Keep sensitive personal data out of metadata and logs.

The API client's `marketingOptOuts`, `putMarketingOptOut` and `deleteMarketingOptOut` manage service client marketing preferences. These differ from provider suppressions for bounces/complaints. Do not bypass either with a transactional label or treat a provider suppression as a marketing unsubscribe. The service enforces its own applicable policies; the host remains responsible for consent and business classification.
