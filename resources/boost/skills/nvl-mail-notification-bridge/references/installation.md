# Install and publish: nvl/mail-notification-bridge

Targets PHP 8.4+ and Laravel 13. Install the selected NVL dependency closure on compatible 7.x versions.

```sh
composer require nvl/mail-notification-bridge:^7.0
```

## Provider discovery

Composer package discovery registers these package-owned providers:

- `Nvl\MailNotificationBridge\Providers\MailNotificationsServiceProvider`

Do not publish or copy vendor providers into the application. If discovery is deliberately disabled, register these exact classes in the application’s `bootstrap/providers.php`. Host policy and extension bindings belong in an application provider; see [usage](usage.md) for which bindings are required.

## Skills

Laravel Boost discovers this folder at `resources/boost/skills/` in the installed Composer package. In the consumer application, run:

```sh
php artisan boost:install --skills --no-interaction
```

Select skills through the installed Boost configuration when setting it up interactively. `php artisan boost:update --no-interaction` refreshes selected resources. Boost is a consumer development tool, not a required runtime dependency of this package. Agent Skills folders can also be copied to the skill directory supported by another agent; copy the entire folder so references and any scripts remain available.

The package also exposes its native Laravel publish group:

```sh
php artisan vendor:publish --tag=nvl-mail-notification-bridge-skills --no-interaction
```

That group copies the full skill tree to `.agents/skills`. Avoid force-overwriting application-authored changes; keep local conventions separate from vendor guidance.

## Publishable groups

- `nvl-mail-notification-bridge-config`
- `nvl-mail-notification-bridge-migrations`
- `nvl-mail-notification-bridge-skills`

## Configuration

```sh
php artisan vendor:publish --tag=nvl-mail-notification-bridge-config --no-interaction
```

The consumer owns `config/nvl-mail-notification-bridge.php`. Inspect the actual files registered by this package's config publish group; published sections differ between packages. Set deployment configuration before provider boot when it selects a model, registry or integration. Never store closures in cacheable configuration; bind behavior in an application provider. Rebuild configuration caches and restart long-lived workers after reviewed changes.

## Migrations

Choose exactly one migration owner before the first migration:

- **Vendor-loaded:** enable the package migration loader and run ordinary `php artisan migrate --no-interaction` after reviewing storage and bindings.
- **Published:** publish the tagged files, disable `nvl-mail-notification-bridge.migrations.enabled` before migrating, then review and edit the application-owned copies before their first execution.

```sh
php artisan vendor:publish --tag=nvl-mail-notification-bridge-migrations --no-interaction
```

Publish migrations once during initial setup. Laravel retimestamps published migrations; publishing again can create duplicate dated copies even without `--force`. Update config or skills with their separate tags, and avoid a blanket `--provider` publication after migration setup. Do not run both vendor and host migration copies. Keep applied history intact; evolve an installed schema with a new reviewed host migration. Preserve required keys, unique constraints, indexes, ownership declarations and transaction connection.

Configure `nvl-mail-notification-bridge.connection` and supported table mappings before migrations, together with any host model declarations. Publication does not create tables, establish actor policy, admit an owner or prove native Ownership adoption.

**Bridge defaults:** receipt migrations and the HTTP receiver both default to disabled. Outbound sending needs no package table. Only enable receipt storage for callbacks; follow [callbacks](callbacks.md) for setup and destructive rollback implications, and [upgrading](upgrading.md) before replacing the copied SDK. Host business authorization remains local; no engine Owner/Client models or provider credentials are installed in the consumer.

## Artisan operations

These commands are registered by Composer-discovered package providers. Feature-gated commands require the corresponding enabled adapter/runtime. Read each installed command’s `--help` before supplying operation options:

- `php artisan nvl:mail-notification-bridge:doctor --help`
- `php artisan nvl:mail-notification-bridge:webhooks:recover --help`

## Verify the consumer

Verify authorized recipient routing, explicit API profile selection, immutable prepared payload/key retries, locale restoration and optional signed durable receipts. Run the host integration tests and doctor. Publication is not delivery or production certification.

Official framework guidance: [Laravel package development](https://laravel.com/docs/13.x/packages) and [Boost third-party package skills](https://laravel.com/docs/13.x/boost#third-party-package-skills).
