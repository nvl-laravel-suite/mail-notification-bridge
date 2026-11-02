<?php

declare(strict_types=1);

use Nvl\MailNotificationBridge\Tests\TestCase;

require_once __DIR__.'/bootstrap.php';

pest()->extend(TestCase::class)->in('Feature');
