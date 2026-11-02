---
name: nvl-mail-notification-bridge
description: Use when a Laravel app consumes a remote Mail Notifications HTTP service through HTML/CSS, Mailables, notification routing, business-reference history queries or signed delivery callbacks. Also use when choosing between this consumer bridge and the embedded nvl/mail-notifications delivery engine.
---

# NVL Mail Notification Bridge

## Choose the boundary first

This package is the **remote HTTP consumer**. The host owns authorization, content and business models; the service owns delivery, providers, sender defaults and retention. For embedded delivery in this Laravel app, use `nvl/mail-notifications` instead. Do not install both by default. This bridge needs no provider credentials, Media, broker, editor or dashboard.

Before editing, confirm PHP 8.4+/Laravel 13, installed package versions and the host's conventions. Read [installation](references/installation.md) for exact config, commands and publication tags; [API](references/api.md) for signatures. Use public services/traits, not internal receipt jobs as a submission API.

## Select the workflow

- **Prepared HTML/CSS or multiple client profiles:** [setup](references/getting-started.md). Bearer keys select the client. Resolve named profiles from trusted host context; names from a request are not authorization. Named profiles do not inherit URL/token/client/signing secrets.
- **English/Bulgarian Mailables, users, orders, arbitrary recipients:** [Laravel recipes](references/laravel.md). Render once with `SubmitsMailNotifications`; retain payload and logical key for retries. The trait does not send local SMTP. Notification payloads omit `to`; the notifiable routes recipients. Use saved mapped models for automatic references. The built-in notification queues after commit.
- **Resource history, filters, time windows and pagination:** [history](references/history.md). Use stable aliases and IDs, not remote PHP model classes. Authorize locally before querying. Builders are immutable; retain returned queries. Time upper bounds are exclusive. Identical resource IDs under different service clients remain isolated.
- **Signed callbacks and business effects:** [callbacks](references/callbacks.md). Enable receipt schema and durable worker only when needed. This receives service callbacks, not provider webhooks. Preserve raw bytes; bind signatures to the configured service client. A receipt acknowledgement is not listener completion. Business effects require their own transactional deduplication/outbox.
- **Timeouts, attachment limits, recovery and releases:** [operations](references/operations.md). A timeout has an unknown outcome: retry the exact payload/key, not a newly rendered Mailable. Notification-channel keys and direct-send keys differ; keep one path per operation. Receipt rollback drops data; choose one migration owner. Drain old serialized jobs before namespace upgrades.

## Verify the host integration

Prove authorized recipients, locale restoration, supported attachments, same-key retries and explicit client-profile selection with HTTP-boundary tests. Exercise actual signed duplicate/conflict/recovery cases when callbacks are enabled. Run `doctor`, but do not treat it as end-to-end delivery proof. Local SMTP, real provider sending and public HTTPS callback delivery are distinct checks; report unverified external behavior plainly.
