# Consumer operations and recovery

## Transport and retry safety

The client uses Laravel HTTP with 5-second connect and 30-second response timeouts by default, disables redirects, and does not automatically retry mutations. HTTP failures throw `RequestException`; connection/timeouts can throw `ConnectionException`. A timeout is an **unknown outcome**, not proof that the service rejected the send. Reuse the same operation key and exact prepared payload; inspect history when needed. Preserve service validation/rate-limit errors rather than blindly retrying every 4xx.

The built-in queued notification uses three attempts and `[10, 60]` backoff and dispatches after commit. Your own host jobs need a bounded retry policy and persisted logical keys/payloads. Starting a new key on every job attempt defeats service idempotency. Worker timeouts must exceed the bounded HTTP request and remain shorter than your queue's visibility/retry interval.

The service owns provider retry state and testing interception. A successful consumer HTTP request is not evidence of SMTP/provider delivery. Verify the service message, attempts, recipient events and provider message ID separately. For local QA use only isolated client keys, explicit abilities, controlled inboxes and local SMTP.

## Attachments and streams

`SubmitsMailNotifications` reads a cloned Mailable through an isolated array transport; it does not send through the host SMTP transport. It supports prepared HTML/text, To/Cc/Bcc, attachments and inline images, plus declared message options. From/Reply-To are not forwarded; unsupported custom headers and preparation options fail validation; this is not a generic MIME relay. Current preparation bounds are 10 MiB per file and 20 MiB total before service-side request/provider limits. Base64 expands the request; remote file limits may be stricter.

For explicit JSON requests, send `attachments`/`inline_assets` following the service contract. `sendMultipart($fields, $files, $key)` uploads local paths and closes its own opened input streams. URL-based attachments are localized by the service under its URL policy; the bridge does not fetch arbitrary remote URLs on the host. For streamed asset/message-attachment downloads, provide a writable output resource and close it in your own `finally` block. Download methods return void and throw on HTTP errors; only a normal return means the copy completed. Handle exceptions and discard partial output files. Expired remote attachment files cannot be restored from metadata alone.

## Durable callback workers

Enable only if the host needs callbacks; otherwise leave both receiver and callback migrations off. `ProcessWebhookReceipt` has a 60-second timeout, five receipt attempts with `[10, 60, 300, 900]` retry delay and a 180-second lease. Use a worker timeout of 60 seconds and a queue `retry_after`/visibility interval greater than 60 and less than 180 seconds (for example 120). Bound listener work inside the lease. Long external tasks should go through a separate host outbox.

Example for a database/Redis queue connection named `database` and configured `webhooks.queue=mail-callbacks`:

```bash
php artisan queue:work database --queue=mail-callbacks --timeout=60 --tries=5
php artisan nvl:mail-notification-bridge:webhooks:recover --limit=100
php artisan nvl:mail-notification-bridge:doctor --format=json
```

The queue connection and queue name must match your configuration; do not copy these names without configuring them. Schedule `nvl:mail-notification-bridge:webhooks:recover` regularly (for example every minute) to enqueue due pending receipts and recover expired leases. After five failed receipt attempts, status is `failed`; investigate and reconcile the business effect before any manual replay. Recovery is not an unconditional resend of completed events. Monitor pending age, failed receipts, failed queue jobs, worker availability and host listener failures. `doctor` checks configuration/schema/route/queue prerequisites; it does not prove external HTTPS delivery or listener success.

## Release and rollback

Publish configuration deliberately; `--force` can replace host customizations. Use exactly one owner for receipt migrations. Schema rollback is destructive and removing the package does not reverse effects already applied by listeners. Drain queued jobs before changing serialized namespaces. See [UPGRADING](../UPGRADING.md) for the transition from the earlier copied client. Rebuild deployment caches and restart long-lived workers after configuration/code changes.

## Test layers

Use HTTP fakes for host authorization, locale, recipient routing, request payloads, stable keys, error handling and client-profile selection. Use a real receipt database and durable queue for signed raw-body, duplicate/conflict, commit-before-acknowledgement and recovery checks. Keep local SMTP verification in an isolated service plus clean consumer application. Do not use provider credentials, production recipient lists or a fake callback signature as evidence of real provider webhook delivery.

The package is backend-only: no UI assets, Media tables, provider SDK or template editor need publishing into a remote consumer. Canonical API signatures are in the [PHP API](../resources/boost/skills/nvl-mail-notification-bridge/references/api.md).
