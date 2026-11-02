# Mail Notification Bridge callback events

This consumer bridge owns no mail-delivery domain lifecycle. The service owns delivery facts. Its versioned external callbacks are exposed as the Laravel integration event `Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived`, independently of NVL's local DomainEvent dispatcher. The machine-readable distinction is in [event-catalog.json](../resources/event-catalog.json).

The optional HTTP route verifies raw-body HMAC signatures, timestamp freshness, event identity and the configured client UUID. It persists an immutable inbox receipt on the configured database connection and enqueues only after that connection's outer commit. An HTTP request inside an outstanding inbox transaction receives 503 rather than an uncommitted acknowledgement. Queue dispatch failure leaves a recoverable receipt.

`ProcessWebhookReceipt` claims a fenced lease, validates the persisted payload, dispatches the Laravel event, and marks completion only for its current lease. Listener failure is retryable with persisted backoff, capped at five attempts. Run a durable worker and schedule `nvl:mail-notification-bridge:webhooks:recover` every minute for enqueue failures and expired leases. Host listener effects are at least once and can complete before a worker crash; deduplicate them by `(connection, eventId)` in your own transaction.

The event has readonly `connection`, `eventId` and `payload` fields. `type()`, `messageId()` and `references()` expose validated values. `connection` is the trusted API profile name, not a database connection. The payload's `version` is 1 and includes client/message IDs, status, business references and nullable legacy provenance IDs. It contains no recipient email, message body, attachment bytes, credentials or raw provider exceptions.

For additional metadata, language, recipients or content, use the matching profile to retrieve the message:

```php
namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived;

final class ObserveMailDelivery
{
    public function __construct(private MailNotificationsManager $clients) {}

    public function handle(MailNotificationReceived $event): void
    {
        $message = $this->clients->connection($event->connection)->message($event->messageId())['data'];
        Log::info('Mail delivery state observed', [
            'connection' => $event->connection,
            'event_id' => $event->eventId,
            'message_id' => $event->messageId(),
            'status' => $message['status'],
        ]);
    }
}
```

This example only observes status. Register the listener through Laravel event discovery or explicit event mapping. Real business mutations require the host transaction/deduplication or outbox described in [callbacks](callbacks.md). Do not select a profile from arbitrary request data. Callback events can arrive out of order; reconcile current state using the message API. Duplicate identical callbacks acknowledge without duplicate processing, conflicting payloads for an existing identity return 409, and cross-client signed payloads return 401. SMTP submission events establish provider acceptance, not inbox delivery.
