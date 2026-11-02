# Upgrading

This is a new NVL 7 package for Laravel 13 and PHP 8.4+. When adopting from `nvl/mail-notifications-client`, update imports from `Nvl\MailNotificationsClient` to `Nvl\MailNotificationBridge`, and configuration to `nvl-mail-notification-bridge`. The provider is under `Providers`. HTTP API payloads and responses and the Laravel `mail-notifications` routing channel stay unchanged. Existing environment variable names remain supported.

The old SDK is not automatically removed or aliased. Install one SDK in a consumer, update queued notification classes intentionally, and drain old jobs before removing the old namespace. Do not overwrite old release artifacts.

Callback storage is optional and fresh-install only. The one alpha schema defaults to `nvl_mail_notification_bridge_webhook_receipts`. Existing consumers must retain their old inbox while draining callbacks; no destructive automatic migration is supplied. Database/table names are configurable.
