# NVL Mail Notification Bridge

A Laravel consumer of the Mail Notifications HTTP API. Send host-rendered HTML/CSS or Mailables, use Laravel notifications, query client-scoped history and receive signed delivery callbacks.

## Choose the package

| Requirement | Package | Responsibilities in this application |
| --- | --- | --- |
| Call a separately hosted mail service | `nvl/mail-notification-bridge` | Business authorization, content, recipient routing, API credentials, optional callback receipts |
| Run delivery inside this Laravel application | `nvl/mail-notifications` | Provider credentials, queues, attachments through `nvl/media`, delivery/event storage, sync and callback outbox |

Choose the bridge for remote consumption. Choose the delivery engine for embedded delivery or building the service itself. Installing both is not required. The bridge depends on `nvl/core`, not the engine, Media, a broker or a template editor. It does not install a dashboard or provider webhook receiver. Laravel owns your views and templates; the service accepts prepared content.

Requires PHP 8.4+ and Laravel 13, with either Symfony line Laravel 13 supports: Mailer 7.4.12+ or 8.0+, and Mime 7.4.22+ or 8.1.10+. Install from your configured NVL Composer repository:

```bash
composer require nvl/mail-notification-bridge:^7.0
php artisan vendor:publish --tag=nvl-mail-notification-bridge-config
```

## Guides

- [Setup, credentials, named clients and raw HTML/CSS](docs/getting-started.md)
- [English/Bulgarian Mailables, users, orders and arbitrary emails](docs/laravel.md)
- [Model references, history, filters and pagination](docs/history.md)
- [Signed callbacks, receipt storage and listener safety](docs/callbacks.md)
- [Queues, retries, attachments, recovery and testing](docs/operations.md)
- [Public PHP API](resources/boost/skills/nvl-mail-notification-bridge/references/api.md)
- [PHP events](docs/events.md), [upgrades](UPGRADING.md), [security](SECURITY.md), [contributing](CONTRIBUTING.md)

## Agent guidance

```bash
php artisan vendor:publish --tag=nvl-mail-notification-bridge-skills
```

This publishes the self-contained `nvl-mail-notification-bridge` skill into the host's `.agents/skills`. Read its `SKILL.md` before integrating. On upgrades, review local modifications before republishing with `--force`. Human guides generate the corresponding portable skill references; consumers do not need the suite checkout.

## Verification

Package tests execute the documented bilingual Mailable and user/order/arbitrary-recipient recipes against a fake HTTP boundary. Separate clean-Laravel QA verifies Composer discovery, live local SMTP, attachments, client isolation and signed callbacks. These are local checks, not a claim that your production provider, queue, HTTPS endpoint or recovery setup has been verified.
