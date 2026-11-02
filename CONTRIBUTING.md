# Contributing

This repository is a read-only release mirror. Pull requests to the mirror are not accepted. Open a public issue with a reproducible example or a proposed patch. Maintainers apply reviewed changes in the private source repository and publish immutable releases from there.

Report vulnerabilities privately through the [security policy](https://github.com/nvl-laravel-suite/mail-notification-bridge/security/policy). Public issues must not contain undisclosed vulnerability details.

Run the owning package regression suite and strict PHPStan gate before proposing changes. Keep the bridge independent from the delivery engine and host app.

## Documentation and skill maintenance

Human integration guides live in `docs/`. Do not maintain duplicate copies in the published skill: the suite's `tools/package-skill-docs.php --package=mail-notification-bridge --write` exports portable references. Regenerate them after guide/API changes and mirror this package's skill into the suite aggregate. Run `tests/Contract/MailConsumerDocumentationTest.php`, the package test suite, PHPStan and the scoped generation check. Documented bilingual Mailable and notification recipes are executable regressions; keep those markers and examples in sync. Test actual publication in a disposable Laravel host, never by overwriting an operator's customized agent directory.
