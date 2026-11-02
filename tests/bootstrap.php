<?php

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

$autoload = __DIR__.'/../vendor/autoload.php';
if (! is_file($autoload)) {
    $autoload = dirname(__DIR__, 4).'/vendor/autoload.php';
}

/** @var ClassLoader $loader */
$loader = require $autoload;
$loader->addPsr4('Nvl\\MailNotificationBridge\\Tests\\', __DIR__.'/');
$loader->addPsr4('Nvl\\MailNotificationBridge\\', __DIR__.'/../src/');
