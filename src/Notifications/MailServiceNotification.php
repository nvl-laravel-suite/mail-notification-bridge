<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Nvl\MailNotificationBridge\Notifications\Channels\MailNotificationsChannel;
use Nvl\MailNotificationBridge\Notifications\Messages\MailNotificationsMessage;

/** Ready-to-use Laravel notification for prepared content.
 * @api
 */
class MailServiceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly MailNotificationsMessage $message)
    {
        $this->afterCommit();
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [MailNotificationsChannel::class];
    }

    public function toMailNotifications(object $notifiable): MailNotificationsMessage
    {
        return $this->message;
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }
}
