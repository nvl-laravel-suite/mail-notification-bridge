<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Concerns;

use Illuminate\Mail\Mailable;
use Nvl\MailNotificationBridge\Mail\MailablePreparation;
use Nvl\MailNotificationBridge\MailNotificationsManager;

/**
 * Render a host Mailable without SMTP and submit its supported content to the service.
 *
 * The service owns From and Reply-To configuration. Host business authorization stays local.
 *
 * @mixin Mailable
 *
 * @api
 */
trait SubmitsMailNotifications
{
    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function toMailNotifications(array $options = []): array
    {
        return app(MailablePreparation::class)->prepare($this, $options);
    }

    /** @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function submitMailNotifications(string $idempotencyKey, array $options = [], ?string $connection = null): array
    {
        $payload = $this->toMailNotifications($options);

        return app(MailNotificationsManager::class)->connection($connection ?? 'default')->send($payload, $idempotencyKey);
    }
}
