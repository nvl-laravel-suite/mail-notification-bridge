<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Nvl\MailNotificationBridge\Console\Commands\Doctor;
use Nvl\MailNotificationBridge\Console\Commands\RecoverWebhooks;
use Nvl\Support\Globals\GlobalNames;

class WebhooksServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RecoverWebhooks::class, Doctor::class]);
        }

        if ($this->app->make('config')->get('nvl-mail-notification-bridge.webhooks.enabled', false)) {
            $router = $this->app->make(Router::class);
            $router->getRoutes()->refreshNameLookups();
            $names = $this->app->make(GlobalNames::class);
            $names->reserve(
                'mail-notification-bridge',
                'route',
                'nvl-mail-notification-bridge.webhooks',
                static fn (string $name): bool => $router->getRoutes()->getByName($name) !== null,
                function (string $name): void {
                    $this->loadRoutesFrom(__DIR__.'/../../routes/webhooks.php');
                },
            );
        }
    }
}
