# Public API: nvl/mail-notification-bridge

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\MailNotificationBridge\Concerns\HasMailNotifications`
- `Nvl\MailNotificationBridge\Concerns\SubmitsMailNotifications`
- `Nvl\MailNotificationBridge\Definitions\Tables\MailNotificationBridgeTables`
- `Nvl\MailNotificationBridge\MailNotificationsClient`
- `Nvl\MailNotificationBridge\MailNotificationsManager`
- `Nvl\MailNotificationBridge\MessagesQuery`
- `Nvl\MailNotificationBridge\Notifications\Channels\MailNotificationsChannel`
- `Nvl\MailNotificationBridge\Notifications\MailServiceNotification`
- `Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage`
- `Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived`

## Nvl\MailNotificationBridge\Concerns\HasMailNotifications

```text
trait Nvl\MailNotificationBridge\Concerns\HasMailNotifications
@mixin Model
resolved @mixin Illuminate\Database\Eloquent\Model
public function mailNotificationReference(): array
@return array{type: string, id: string}
public function mailNotificationReferenceType(): ?string
public function mailNotifications(?string $connection=null): Nvl\MailNotificationBridge\MessagesQuery
```

## Nvl\MailNotificationBridge\Concerns\SubmitsMailNotifications

```text
trait Nvl\MailNotificationBridge\Concerns\SubmitsMailNotifications
@mixin Mailable
resolved @mixin Illuminate\Mail\Mailable
public function submitMailNotifications(string $idempotencyKey, array $options=[], ?string $connection=null): array
@param array<string, mixed> $options
@return array<string, mixed>
public function toMailNotifications(array $options=[]): array
@param array<string, mixed> $options
@return array<string, mixed>
```

## Nvl\MailNotificationBridge\Definitions\Tables\MailNotificationBridgeTables

```text
final class Nvl\MailNotificationBridge\Definitions\Tables\MailNotificationBridgeTables
public const string WebhookReceipts="nvl_mail_notification_bridge_webhook_receipts"
```

## Nvl\MailNotificationBridge\MailNotificationsClient

```text
class Nvl\MailNotificationBridge\MailNotificationsClient
public function __construct(string $baseUrl, string $token, int $connectTimeout=5, int $timeout=30): mixed
public function archiveAsset(string $asset): void
public function asset(string $asset): array
@return array<string, mixed>
public function assets(array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function cancelMessage(string $message): array
@return array<string, mixed>
public function client(): array
@return array<string, mixed>
public function configuration(): array
@return array<string, mixed>
public function createFlag(array $data): array
@param array<string, mixed> $data
@return array<string, mixed>
public function createKey(array $data): array
@param array<string, mixed> $data
@return array<string, mixed>
public function createWebhookEndpoint(array $data): array
@param array<string, mixed> $data
@return array<string, mixed>
public function deleteFlag(string $flag): void
public function deleteMarketingOptOut(string $optOut): void
public function deleteWebhookEndpoint(string $endpoint): void
public function downloadAsset(string $asset, mixed $destination): void
@param resource $destination
public function downloadMessageAttachment(string $message, string $attachment, mixed $destination): void
@param resource $destination
public function events(string $id, int $page=1, int $perPage=50): array
@return array<string, mixed>
public function flag(string $flag): array
@return array<string, mixed>
public function flags(array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function keys(array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function marketingOptOuts(array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function message(string $id): array
@return array<string, mixed>
public function messageProvider(string $message): array
@return array<string, mixed>
public function messages(array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function publishAsset(string $asset): array
@return array<string, mixed>
public function putMarketingOptOut(string $email): array
@return array<string, mixed>
public function query(): Nvl\MailNotificationBridge\MessagesQuery
public function retryMessage(string $message): array
@return array<string, mixed>
public function revokeKey(string $key): void
public function rotateWebhookSecret(string $endpoint): array
@return array<string, mixed>
public function send(array $message, string $idempotencyKey): array
@param array<string, mixed> $message
@return array<string, mixed>
public function sendMultipart(array $fields, array $files, string $idempotencyKey): array
@param array<string, string> $fields
@param array<string, string> $files
@return array<string, mixed>
public function updateClient(array $settings): array
@param array<string, mixed> $settings
@return array<string, mixed>
public function updateFlag(string $flag, array $data): array
@param array<string, mixed> $data
@return array<string, mixed>
public function updateWebhookEndpoint(string $endpoint, array $data): array
@param array<string, mixed> $data
@return array<string, mixed>
public function uploadAsset(string $path, ?string $name=null): array
@return array<string, mixed>
public function webhookDeliveries(string $endpoint, array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
public function webhookEndpoint(string $endpoint): array
@return array<string, mixed>
public function webhookEndpoints(array $filters=[]): array
@param array<string, mixed> $filters
@return array<string, mixed>
```

## Nvl\MailNotificationBridge\MailNotificationsManager

```text
final class Nvl\MailNotificationBridge\MailNotificationsManager
public function __construct(array $config): mixed
@param array<string, mixed> $config
public function configuration(string $name="default"): array
@return array<string, mixed>
public function connection(string $name="default"): Nvl\MailNotificationBridge\MailNotificationsClient
public function connectionName(string $name="default"): string
```

## Nvl\MailNotificationBridge\MessagesQuery

```text
final readonly class Nvl\MailNotificationBridge\MessagesQuery
public function __construct(Nvl\MailNotificationBridge\MailNotificationsClient $client, array $filters=[]): mixed
@param array<string, mixed> $filters
public function category(string $category): self
public function createdBetween(DateTimeInterface|string $from, DateTimeInterface|string $to): self
public function forModel(Illuminate\Database\Eloquent\Model $model): self
public function forRecipient(string $email): self
public function forReference(string $type, string|int $id): self
public function forReferences(string $type, array $ids): self
@param array<array-key, (int | string)> $ids
public function ids(array $ids): self
@param array<array-key, string> $ids
public function language(string $language): self
public function lazyAll(int $perPage=100, int $maxPages=10000): Illuminate\Support\LazyCollection
@return LazyCollection<int, array<string, mixed>>
resolved @return Illuminate\Support\LazyCollection<int, array<string, mixed>>
public function paginate(int $page=1, int $perPage=25): array
@return array<string, mixed>
public function status(string $status): self
public function statuses(array $statuses): self
@param array<array-key, string> $statuses
public function tag(string $tag): self
public function type(string $type): self
```

## Nvl\MailNotificationBridge\Notifications\Channels\MailNotificationsChannel

```text
class Nvl\MailNotificationBridge\Notifications\Channels\MailNotificationsChannel
public function __construct(?Nvl\MailNotificationBridge\MailNotificationsClient $client=null): mixed
public function send(object $notifiable, Illuminate\Notifications\Notification $notification): ?array
@return (array<string, mixed> | null)
```

## Nvl\MailNotificationBridge\Notifications\MailServiceNotification

```text
class Nvl\MailNotificationBridge\Notifications\MailServiceNotification extends Illuminate\Notifications\Notification implements Illuminate\Contracts\Queue\ShouldQueue
public function __construct(Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage $message): mixed
public function backoff(): array
@return list<int>
public function toMailNotifications(object $notifiable): Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage
public function via(object $notifiable): array
@return array<int, string>
public readonly Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage $message
public int $tries=3
```

## Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage

```text
readonly class Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage
public function __construct(array $payload, string $idempotencyKey, ?string $connection=null): mixed
@param array<string, mixed> $payload
public function category(?string $category): static
public function language(?string $language): static
public function metadata(array $metadata): static
@param array<array-key, mixed> $metadata
public function tags(array $tags): static
@param list<string> $tags
public function type(string $type): static
public readonly ?string $connection
public readonly string $idempotencyKey
public readonly array $payload
@param array<string, mixed> $payload
```

## Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived

```text
final readonly class Nvl\MailNotificationBridge\Webhooks\Events\MailNotificationReceived
public function __construct(string $connection, string $eventId, array $payload): mixed
@param array<array-key, mixed> $payload
public function messageId(): string
public function references(): array
@return list<array{type: string, id: string}>
public function type(): string
public readonly string $connection
public readonly string $eventId
public readonly array $payload
@var Callback
resolved @var array{version: 1, id: non-empty-string, client_id: non-empty-string, type: non-empty-string, occurred_at: string, data: array{message_id: non-empty-string, status: non-empty-string, recipient_id: (non-empty-string | null), template_version_id: (non-empty-string | null), composition_revision_id: (non-empty-string | null), error_code: (non-empty-string | null), references: list<array{type: non-empty-string, id: non-empty-string}>}}
```
