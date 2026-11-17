<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends LaravelTestCase
{
    private ?string $applicationRoot = null;

    public function createApplication(): Application
    {
        $this->applicationRoot ??= sys_get_temp_dir().'/mail-notifications-client-'.bin2hex(random_bytes(8));
        $files = new Filesystem;
        foreach (['bootstrap/cache', 'config', 'storage/framework/cache', 'storage/framework/views', 'vendor/composer'] as $directory) {
            $files->ensureDirectoryExists($this->applicationRoot.'/'.$directory);
        }
        $package = json_decode($files->get(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $files->put($this->applicationRoot.'/vendor/composer/installed.json', json_encode([
            'packages' => [['name' => $package['name'], 'extra' => $package['extra']]],
        ], JSON_THROW_ON_ERROR));

        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app = Application::configure(basePath: $this->applicationRoot)->withEvents(false)->create();
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Engine selected for this run through DB_CONNECTION; in-memory SQLite when none is selected.
     */
    public static function selectedDriver(): string
    {
        $connection = getenv('DB_CONNECTION');

        return is_string($connection) && $connection !== '' ? $connection : 'sqlite';
    }

    /**
     * Point the default connection, and any named inbox connections, at the selected engine with no tables.
     * Inbox connections share the engine but use their own table prefix, so they stay isolated from the default.
     */
    protected function useSelectedDatabase(string ...$inboxConnections): void
    {
        $driver = self::selectedDriver();
        $default = $driver === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true, 'prefix' => '']
            : config('database.connections.'.$driver);
        config(['database.default' => $driver, 'database.connections.'.$driver => $default]);
        foreach ($inboxConnections as $connection) {
            config(['database.connections.'.$connection => [...$default, 'prefix' => $connection.'_']]);
        }
        foreach ([$driver, ...$inboxConnections] as $connection) {
            DB::purge($connection);
            DB::connection($connection)->getSchemaBuilder()->dropAllTables();
        }
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if ($this->applicationRoot !== null) {
                (new Filesystem)->deleteDirectory($this->applicationRoot);
            }
        }
    }
}
