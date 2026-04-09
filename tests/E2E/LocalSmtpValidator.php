<?php

namespace SMTPValidateEmail\Tests\E2E;

use SMTPValidateEmail\SMTPValidateEmail;

/**
 * Validator subclass that routes all MX lookups to 127.0.0.1
 * for testing against a local SMTP server.
 */
class LocalSmtpValidator extends SMTPValidateEmail
{
    protected function mx_query($domain): array
    {
        return [['127.0.0.1'], [10]];
    }
}
