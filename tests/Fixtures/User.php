<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;
use Nvl\MailNotificationBridge\Concerns\HasMailNotifications;

class User extends Model
{
    use HasMailNotifications;
    use Notifiable;
}
