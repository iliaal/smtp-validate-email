# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-04-09

### Added

- PHPUnit test suite with 64 unit tests and 11 end-to-end tests
- End-to-end tests using a local aiosmtpd SMTP server for deterministic validation
- Email format validation via `filter_var()` before attempting SMTP connections
- Duplicate email address deduplication in `set_emails()`
- EOF detection in `connected()` to catch half-closed connections
- PHP 8.1+ minimum version requirement

### Changed

- `recv()` buffer size increased from 1024 to 4096 bytes to handle long EHLO responses
- Catch-all probe address uses `random_bytes()` instead of predictable `time()`
- MX fallback domain weight set to `max(weights) + 1` instead of 0, correctly placing it last per RFC 5321
- STARTTLS support with TLS 1.1 and 1.2
- License corrected to GPL-3.0-or-later in composer.json (matching LICENSE.txt)
- README rewritten with full usage documentation, configuration reference, and test instructions
- Project metadata updated in composer.json (owner, homepage, authors)

### Removed

- Dead "IP reverse lookup rejected" workaround in `rcpt()` that could never trigger

### Fixed

- `connected()` now detects remote-closed connections via `feof()` check
- Greylisting and catch-all detection improvements from earlier commits
- SSL/TLS negotiation handling
- Various SMTP response parsing fixes for non-compliant servers

[1.0.0]: https://github.com/iliaal/smtp-validate-email/releases/tag/v1.0.0
