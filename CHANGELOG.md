# Changelog

## [Unreleased]

## [7.0.1] - 2026-11-05

### Fixed

- Reject redirected and other non-successful API responses, including void mutations, and reject empty JSON lists as object envelopes while preserving HTTP exceptions and nested response arrays.
- Require patched Symfony HTTP Foundation 7.4.23+/8.1.11+ and MIME 8.1.10+ for the bridge's direct runtime dependencies.

<!-- NVL GENERATED CHANGES START -->
### Changed

- reject unsuccessful API outcomes
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

- Clarify remote-consumer versus embedded-delivery package selection and responsibility boundaries.
- Split setup, bilingual Laravel recipes, scoped history, signed callbacks and operations into canonical guides with generated portable skill references.
- Execute published examples in regressions and verify skill link closure, syntax and aggregate parity.

- Extract the prepared-content consumer SDK into the NVL suite.
- Preserve HTTP payloads, response envelopes, idempotency and client boundaries.
- Add host Mailable preparation and submission.
- Use canonical configuration and an optional, configurable callback inbox.
