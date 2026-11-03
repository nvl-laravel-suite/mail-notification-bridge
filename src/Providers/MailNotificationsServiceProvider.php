<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Providers;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Nvl\MailNotificationBridge\MailNotificationsClient;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Notifications\Channels\MailNotificationsChannel;
use Nvl\MailNotificationBridge\Webhooks\WebhooksServiceProvider;
use Nvl\Support\Traits\MergesPackageConfiguration;
use Nvl\Support\Traits\RegistersNamespacedResources;

class MailNotificationsServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;
    use RegistersNamespacedResources;

    public function register(): void
    {
        $this->app->bind(MailNotificationsChannel::class, fn (): MailNotificationsChannel => new MailNotificationsChannel);

        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-mail-notification-bridge.php', 'nvl-mail-notification-bridge');

        $this->app->register(WebhooksServiceProvider::class);

        $this->app->singleton(MailNotificationsManager::class, function (Application $app): MailNotificationsManager {
            $value = $app->make('config')->get('nvl-mail-notification-bridge', []);
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Bridge configuration must be a map.');
            }
            $configuration = [];
            foreach ($value as $key => $item) {
                if (! is_string($key)) {
                    throw new \InvalidArgumentException('Bridge configuration keys must be strings.');
                }
                $configuration[$key] = $item;
            }

            return new MailNotificationsManager($configuration);
        });
        $this->app->singleton(MailNotificationsClient::class, fn (Application $app): MailNotificationsClient => $app->make(MailNotificationsManager::class)->connection());
    }

    public function boot(): void
    {
        if (config('nvl-mail-notification-bridge.migrations.enabled', false)) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations/webhooks');
        }
        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../../database/migrations/webhooks' => $this->app->databasePath('migrations'),
            ], 'nvl-mail-notification-bridge-migrations');
            $this->publishes([
                __DIR__.'/../../resources/boost/skills' => base_path('.agents/skills'),
            ], 'nvl-mail-notification-bridge-skills');
            $this->publishes([
                __DIR__.'/../../config/nvl-mail-notification-bridge.php' => $this->app->configPath('nvl-mail-notification-bridge.php'),
            ], 'nvl-mail-notification-bridge-config');
        }
    }
}
