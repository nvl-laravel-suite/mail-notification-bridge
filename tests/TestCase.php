<?php

declare(strict_types=1);

namespace Nvl\MailNotificationBridge\Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

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
