# SMTP Validate Email

Perform email address validation/verification via SMTP.

Retrieves MX records for the email domain and connects to the domain's SMTP server to determine if the address actually exists, without sending a message.

## Features

- Graceful session reset (no message is sent)
- Command-specific timeouts per RFC 2821
- Catch-all account detection
- Batch mode with single connection per domain
- STARTTLS support
- Email format validation via `filter_var` before SMTP probing
- Duplicate address deduplication
- Logging and debug output

## Requirements

- PHP 8.1+
- `getmxrr()` support (standard on Linux; available on Windows since PHP 5.3)

## Installation

```bash
composer require iliaal/smtp-validate-email
```

## Usage

### Single email

```php
use SMTPValidateEmail\SMTPValidateEmail;

$from = 'sender@yourdomain.com';
$email = 'address-to-verify@example.com';

$validator = new SMTPValidateEmail($email, $from);
$results = $validator->validate();

print_r($results);
```

### Batch validation

Emails on the same domain share a single SMTP connection.

```php
use SMTPValidateEmail\SMTPValidateEmail;

$from = 'sender@yourdomain.com';
$emails = [
    'alice@example.com',
    'bob@example.com',
    'someone@other-domain.com',
];

$validator = new SMTPValidateEmail($emails, $from);
$results = $validator->validate();

print_r($results);
```

### Configuration

All options are public properties set before calling `validate()`:

```php
$validator = new SMTPValidateEmail();

// Treat catch-all domains as valid (default: true)
$validator->catchall_is_valid = true;

// Enable catch-all detection (default: false)
$validator->catchall_test = false;

// Treat communication failures as valid (default: false)
$validator->no_comm_is_valid = false;

// Treat connection failures as valid (default: false)
$validator->no_conn_is_valid = false;

// Treat greylisted responses as valid (default: true)
$validator->greylisted_considered_valid = true;

// SMTP port (default: 25)
$validator->connect_port = 25;

// Send NOOP between RCPT TO commands (default: false)
$validator->noop = false;

// Enable debug output (default: false)
$validator->debug = false;
```

### Results

`validate()` returns an associative array:

```php
[
    'user@example.com' => true,          // valid
    'bogus@example.com' => false,        // invalid
    'bogus@example.com_error_msg' => 'No such user',
    'domains' => [
        'example.com' => [
            'users' => ['user', 'bogus'],
            'mxs' => ['mx1.example.com' => 10, 'example.com' => 11],
        ]
    ]
]
```

Pass `false` as the third argument to `validate()` to exclude domain info from results.

### Debug log

```php
$validator->validate($emails, $from);

// Retrieve the log
$log = $validator->get_log();

// Clear it
$validator->clear_log();
```

## Running Tests

### Unit tests

No network required. Uses an in-memory SMTP conversation mock.

```bash
composer install
vendor/bin/phpunit
```

### End-to-end tests

Runs against a local [aiosmtpd](https://pypi.org/project/aiosmtpd/) server that accepts/rejects addresses deterministically. Requires Python 3 with aiosmtpd installed.

```bash
pip3 install aiosmtpd
vendor/bin/phpunit --group e2e
```

The test suite starts and stops the SMTP server automatically.

## License

GPL-3.0-or-later. See [LICENSE.txt](LICENSE.txt).
