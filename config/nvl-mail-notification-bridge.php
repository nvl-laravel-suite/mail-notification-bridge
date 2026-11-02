<?php

declare(strict_types=1);

return [
    'connection' => null,
    'tables' => ['webhook_receipts' => 'nvl_mail_notification_bridge_webhook_receipts'],
    'migrations' => ['enabled' => false],
    'default' => env('MAIL_NOTIFICATIONS_CONNECTION', 'default'),
    'base_url' => env('MAIL_NOTIFICATIONS_URL'),
    'token' => env('MAIL_NOTIFICATIONS_TOKEN'),
    'connect_timeout' => 5,
    'timeout' => 30,
    'client_id' => env('MAIL_NOTIFICATIONS_CLIENT_ID'),
    'webhook_secrets' => array_filter([env('MAIL_NOTIFICATIONS_WEBHOOK_SECRET')]),
    'connections' => [],
    'reference_types' => [],
    'webhooks' => [
        'enabled' => false,
        'path' => 'mail-notifications/webhooks/{connection?}',
        'queue' => 'default',
        'connection' => null,
        'tolerance' => 300,
        'max_bytes' => 65536,
    ],
];
