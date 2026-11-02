<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Concerns;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\MessagesQuery;

/**
 * Expose saved business references and explicit client-scoped remote history queries.
 *
 * @mixin Model
 *
 * @api
 */
trait HasMailNotifications
{
    public function mailNotificationReferenceType(): ?string
    {
        $types = config('nvl-mail-notification-bridge.reference_types', []);
        if (! is_array($types)) {
            throw new InvalidArgumentException('Reference types must map aliases to model classes.');
        }
        $type = array_search(static::class, $types, true);

        return is_string($type) ? $type : null;
    }

    /** @return array{type: string, id: string} */
    public function mailNotificationReference(): array
    {
        $type = $this->mailNotificationReferenceType();
        $id = $this->getKey();
        if (! $this->exists || (! is_string($id) && ! is_int($id)) || $type === null
            || ! preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $type)
            || mb_strlen((string) $id) > 191
            || ! preg_match('/\A[^\s\x00-\x1f\x7f](?:[^\x00-\x1f\x7f]*[^\s\x00-\x1f\x7f])?\z/uD', (string) $id)) {
            throw new InvalidArgumentException('Mail notification references require a saved model, a stable configured business alias, and a safe key.');
        }

        return ['type' => $type, 'id' => (string) $id];
    }

    public function mailNotifications(?string $connection = null): MessagesQuery
    {
        $reference = $this->mailNotificationReference();

        return app(MailNotificationsManager::class)->connection($connection ?? 'default')->query()
            ->forReference($reference['type'], $reference['id']);
    }
}
