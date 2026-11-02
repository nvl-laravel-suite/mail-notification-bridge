<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks;

use Illuminate\Support\ServiceProvider;
use Nvl\MailNotificationBridge\Webhooks\Console\Doctor;
use Nvl\MailNotificationBridge\Webhooks\Console\RecoverWebhooks;

class WebhooksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RecoverWebhooks::class, Doctor::class]);
        }

        if ($this->app->make('config')->get('nvl-mail-notification-bridge.webhooks.enabled', false)) {
            $this->loadRoutesFrom(__DIR__.'/../../routes/webhooks.php');
        }
    }
}
