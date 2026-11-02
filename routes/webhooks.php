<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Nvl\MailNotificationBridge\Webhooks\Http\ReceiveWebhook;
use Nvl\MailNotificationBridge\Webhooks\Http\VerifyWebhook;

Route::post(config('nvl-mail-notification-bridge.webhooks.path', 'mail-notifications/webhooks/{connection?}'), ReceiveWebhook::class)
    ->middleware(VerifyWebhook::class)
    ->name('nvl-mail-notification-bridge.webhooks');
