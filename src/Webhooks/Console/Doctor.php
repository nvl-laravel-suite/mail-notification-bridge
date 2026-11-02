<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Webhooks\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Routing\Router;
use Nvl\MailNotificationBridge\MailNotificationsManager;
use Nvl\MailNotificationBridge\Webhooks\Http\VerifyWebhook;
use Nvl\MailNotificationBridge\Webhooks\ReceiptStore;
use Nvl\MailNotificationBridge\Webhooks\SigningKey;
use Nvl\MailNotificationBridge\Webhooks\WebhookPayload;
use Nvl\Support\Config\PackageStorage;
use Throwable;

class Doctor extends Command
{
    protected $signature = 'nvl:mail-notification-bridge:doctor {--strict : Treat warnings as failures} {--format=text : Output format: text or json}';

    protected $description = 'Check local SDK and optional webhook configuration without network calls';

    public function handle(MailNotificationsManager $manager, Repository $config, ReceiptStore $receipts, DatabaseManager $database, Router $router): int
    {
        $errors = [];
        try {
            $profile = $manager->configuration();
            $url = is_string($profile['base_url'] ?? null) ? parse_url($profile['base_url']) : false;
            if ($url === false || ! in_array($url['scheme'] ?? '', ['https', 'http'], true) || empty($url['host'])
                || ! is_string($profile['token'] ?? null) || trim($profile['token']) === '') {
                $errors[] = 'Base URL and token must be configured for the default connection.';
            }
        } catch (Throwable) {
            $errors[] = 'The default Mail Notifications connection is invalid.';
        }

        if ($config->get('nvl-mail-notification-bridge.webhooks.enabled', false)) {
            $profiles = $config->get('nvl-mail-notification-bridge.connections', []);
            $names = array_unique(['default', ...array_keys(is_array($profiles) ? $profiles : [])]);
            foreach ($names as $name) {
                if (! is_string($name)) {
                    $errors[] = 'Webhook connections must use string names.';

                    continue;
                }
                try {
                    $profile = $manager->configuration($name);
                    if (! WebhookPayload::isUuid($profile['client_id'] ?? null)) {
                        $errors[] = 'Every webhook connection requires a UUID client_id.';
                    }
                    $secrets = $profile['webhook_secrets'] ?? [];
                    if (! is_array($secrets) || count($secrets) < 1 || count($secrets) > 10
                        || count(array_filter($secrets, fn (mixed $secret): bool => SigningKey::decode($secret) !== null)) !== count($secrets)) {
                        $errors[] = 'Every webhook connection requires valid whsec_ signing secrets.';
                    }
                } catch (Throwable) {
                    $errors[] = 'A webhook connection configuration is invalid.';
                }
            }
            try {
                if (! $database->connection(PackageStorage::connection('mail-notification-bridge'))->getSchemaBuilder()->hasTable(ReceiptStore::table())) {
                    $errors[] = 'Publish and run the nvl-mail-notification-bridge-migrations migration.';
                }
            } catch (Throwable) {
                $errors[] = 'The webhook receipt database is unavailable.';
            }
            if (! $receipts->usesDurableQueue()) {
                $errors[] = 'Configure a durable asynchronous webhook queue connection.';
            }
            $queueConnection = $config->get('nvl-mail-notification-bridge.webhooks.connection') ?: $config->get('queue.default');
            if (is_string($queueConnection) && $config->get('queue.connections.'.$queueConnection.'.driver') === 'database') {
                try {
                    $queueDatabase = $config->get('queue.connections.'.$queueConnection.'.connection');
                    $queueTable = $config->get('queue.connections.'.$queueConnection.'.table', 'jobs');
                    if (($queueDatabase !== null && ! is_string($queueDatabase)) || ! is_string($queueTable)
                        || ! $database->connection($queueDatabase)->getSchemaBuilder()->hasTable($queueTable)) {
                        $errors[] = 'Create the database queue jobs table before processing webhook receipts.';
                    }
                } catch (Throwable) {
                    $errors[] = 'The database queue jobs table is unavailable.';
                }
            }
            $route = $router->getRoutes()->getByName('nvl-mail-notification-bridge.webhooks');
            if ($route === null || ! in_array('POST', $route->methods(), true) || ! in_array(VerifyWebhook::class, $route->gatherMiddleware(), true)) {
                $errors[] = 'The webhook POST route with signature middleware must be registered; rebuild the route cache if needed.';
            }
        }
        if (! in_array($this->option('format'), ['text', 'json'], true)) {
            $this->error('Supported formats: text, json.');

            return self::INVALID;
        }
        if ($this->option('format') === 'json') {
            $this->line(json_encode(['package' => 'nvl/mail-notification-bridge', 'healthy' => $errors === [], 'errors' => array_values(array_unique($errors))], JSON_THROW_ON_ERROR));

            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }
        foreach (array_unique($errors) as $error) {
            $this->error($error);
        }
        if ($errors !== []) {
            return self::FAILURE;
        }
        $this->info('Mail Notifications configuration is ready.');

        return self::SUCCESS;
    }
}
