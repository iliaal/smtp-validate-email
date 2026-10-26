# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.0.0] - 2026-10-26

### Upgrading from 1.x

- Subclasses that override protected `expect()` must add the fourth parameter, `$capability_scan = FALSE`; an override with the 1.x signature is a fatal error at class load.
- Protected `expect()` returns the full final reply text and rethrows `SMTP_Validate_Email_Exception_No_Response` after a dropped connection instead of returning.
- Protected `rcpt()` returns `false` instead of `null` when disconnected.
- The unused protected `$mx_query_ns` property is removed.
- `set_sender()`, and the sender argument of the constructor and `validate()`, throw `\InvalidArgumentException` for senders containing CR, LF, or NUL and for senders that are not a single `local@domain` address without whitespace, `<`, `>`, or `\`, which rejects some quoted local parts.

### Added

- `ssl_verify_peer` property to verify the server certificate during STARTTLS (default `false`, since many MX hosts present mismatched or self-signed certificates).
- `SMTP_Validate_Email_Exception_No_MX_Support`, thrown by `validate()` on PHP builds without `getmxrr()` (Windows) instead of a fatal error.

### Changed

- Protected `expect()` returns the complete final reply text instead of its first word, and `''` instead of the raw line for replies without text; subclasses that read its return value see the new values.
- Protected `expect()` takes a fourth `$capability_scan` parameter, so a subclass that overrides `expect()` must add it to its signature or PHP raises a fatal error at class load. `expect()` also rethrows `SMTP_Validate_Email_Exception_No_Response` after disconnecting instead of returning.
- Protected `rcpt()` returns `false` instead of `null` when disconnected, and lets transport errors propagate to `validate()`.
- `set_sender()`, and the sender argument of the constructor and `validate()`, throw `\InvalidArgumentException` unless the sender is a single `local@domain` address without whitespace, `<`, `>`, or `\`.
- `validate()` resets results, domain info, TLS state, and the debug log on every call, and rebuilds format-rejection results from the last `set_emails()` or constructor list.
- A failed STARTTLS handshake records a TLS error for the domain under `no_comm_is_valid` instead of reconnecting.
- Results carry an `_error_msg` key only when there is an error message.
- Deduplicate large same-domain batches in linear time.

### Removed

- Unused protected `$mx_query_ns` property.

### Fixed

- `expect()` no longer treats a connection dropped mid-reply as success, which could report recipients as valid.
- Reject replies truncated by EOF, so a final `250 OK` without its line ending no longer validates a mailbox.
- Only issue STARTTLS when the EHLO/HELO reply advertises it; a greeting banner containing the word no longer triggers it.
- Use the bare MX hostname as the TLS peer name during STARTTLS.
- `recv()` joins response lines longer than the 4096-byte read buffer instead of splitting them.
- `send()` retries short writes so SMTP commands are never truncated.
- Record a connection failure only after every MX host of the domain fails.
- RCPT transport errors apply `no_comm_is_valid` without overwriting results already recorded for earlier recipients.
- A library exception, MX lookup failure, or socket setup failure on one domain no longer aborts the remaining domains in a batch.
- Close every socket after each domain, including EOF-marked ones and those replaced during MX failover.
- `get_results()` with domain info no longer leaves a `domains` key in later `get_results(false)` reads.
- Keep the advertised priority when the domain itself is listed as an MX instead of demoting it to the fallback weight.
- Keep rejected random catch-all probes out of recipient results; retain their diagnostics in the debug log.
- Do not classify greylisted catch-all probes as accepted random mailboxes; keep the configured greylisting policy for real recipients.
- Reject recipients on domains publishing a single preference-0 null MX without opening SMTP connections or trying the domain fallback.

### Security

- `set_sender()` and `send()` reject CR, LF, and NUL, preventing SMTP command injection through the sender address or any command argument.

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
- Opportunistic STARTTLS support (all TLS versions offered by the stream layer)
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

[Unreleased]: https://github.com/iliaal/smtp-validate-email/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/iliaal/smtp-validate-email/compare/v1.0.0...v2.0.0
[1.0.0]: https://github.com/iliaal/smtp-validate-email/releases/tag/v1.0.0
