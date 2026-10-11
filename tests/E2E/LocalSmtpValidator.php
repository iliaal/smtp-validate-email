<?php

namespace SMTPValidateEmail\Tests\E2E;

use SMTPValidateEmail\SMTPValidateEmail;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Connection;

/**
 * Validator subclass that routes all MX lookups to 127.0.0.1
 * for testing against a local SMTP server. Connection attempts are guarded
 * too, because validate() adds the recipient domain as an MX fallback.
 */
class LocalSmtpValidator extends SMTPValidateEmail
{
    protected function connect($host)
    {
        if ($host !== '127.0.0.1') {
            throw new SMTP_Validate_Email_Exception_No_Connection(
                'Local SMTP fixture refuses non-fixture host: ' . $host
            );
        }
        parent::connect($host);
    }

    protected function mx_query($domain): array
    {
        return [['127.0.0.1'], [10]];
    }
}
