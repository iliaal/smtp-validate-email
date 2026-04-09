<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class ValidateFlowTest extends TestCase
{
    private function createValidator(): TestableValidator
    {
        $v = new TestableValidator();
        $v->set_sender('test@localhost');
        return $v;
    }

    /**
     * Script a standard successful SMTP conversation for a single email.
     */
    private function scriptValidConversation(TestableValidator $v): void
    {
        $v->queueResponse("220 mail.example.com ESMTP\r\n"); // greeting
        $v->queueResponse("250 mail.example.com\r\n");        // EHLO
        $v->queueResponse("250 OK\r\n");                      // MAIL FROM
        $v->queueResponse("250 OK\r\n");                      // NOOP
        $v->queueResponse("250 OK\r\n");                      // RCPT TO
        $v->queueResponse("250 OK\r\n");                      // RSET
        $v->queueResponse("221 Bye\r\n");                     // QUIT
    }

    public function test_valid_email(): void
    {
        $v = $this->createValidator();
        $this->scriptValidConversation($v);
        $results = $v->validate(['user@example.com']);
        $this->assertTrue($results['user@example.com']);
    }

    public function test_invalid_email_550(): void
    {
        $v = $this->createValidator();
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n");    // MAIL FROM
        $v->queueResponse("250 OK\r\n");    // NOOP
        $v->queueResponse("550 No such user\r\n"); // RCPT TO
        $v->queueResponse("250 OK\r\n");    // RSET
        $v->queueResponse("221 Bye\r\n");   // QUIT

        $results = $v->validate(['bogus@example.com']);
        $this->assertFalse($results['bogus@example.com']);
    }

    public function test_helo_rejected(): void
    {
        $v = $this->createValidator();
        $v->no_comm_is_valid = false;
        // Server sends unexpected response to connection
        $v->queueResponse("554 Transaction failed\r\n");

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
    }

    public function test_mail_from_rejected(): void
    {
        $v = $this->createValidator();
        $v->no_comm_is_valid = false;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("550 Sender rejected\r\n"); // MAIL FROM rejected

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
    }

    public function test_multiple_emails_same_domain(): void
    {
        $v = $this->createValidator();
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n");    // MAIL FROM
        $v->queueResponse("250 OK\r\n");    // NOOP
        $v->queueResponse("250 OK\r\n");    // RCPT TO alice
        $v->queueResponse("550 No such user\r\n"); // RCPT TO bogus
        $v->queueResponse("250 OK\r\n");    // RSET
        $v->queueResponse("221 Bye\r\n");   // QUIT

        $results = $v->validate(['alice@example.com', 'bogus@example.com']);
        $this->assertTrue($results['alice@example.com']);
        $this->assertFalse($results['bogus@example.com']);
    }

    public function test_catchall_invalidates_all_users(): void
    {
        $v = $this->createValidator();
        $v->catchall_is_valid = false;
        $v->catchall_test = true;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n");    // MAIL FROM
        $v->queueResponse("250 OK\r\n");    // NOOP
        $v->queueResponse("250 OK\r\n");    // catch-all RCPT TO => accepted = catch-all

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
    }

    public function test_no_comm_is_valid_true(): void
    {
        $v = $this->createValidator();
        $v->no_comm_is_valid = true;
        // Helo rejected
        $v->queueResponse("554 Go away\r\n");

        $results = $v->validate(['user@example.com']);
        $this->assertTrue($results['user@example.com']);
    }

    public function test_no_conn_is_valid_flag(): void
    {
        // Use a subclass that fails to connect
        $v = new class extends TestableValidator {
            protected function connect($host): void
            {
                throw new \SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Connection('Connection refused');
            }
        };
        $v->set_sender('test@localhost');
        $v->no_conn_is_valid = false;

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
    }

    public function test_no_conn_is_valid_true(): void
    {
        $v = new class extends TestableValidator {
            protected function connect($host): void
            {
                throw new \SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Connection('Connection refused');
            }
        };
        $v->set_sender('test@localhost');
        $v->no_conn_is_valid = true;

        $results = $v->validate(['user@example.com']);
        $this->assertTrue($results['user@example.com']);
    }

    public function test_empty_emails_returns_empty(): void
    {
        $v = $this->createValidator();
        $results = $v->validate([]);
        $this->assertEmpty($results);
    }

    public function test_domains_info_contains_mxs(): void
    {
        $v = $this->createValidator();
        $this->scriptValidConversation($v);
        $results = $v->validate(['user@example.com']);

        $this->assertArrayHasKey('example.com', $results['domains']);
        $this->assertArrayHasKey('mxs', $results['domains']['example.com']);
        $this->assertArrayHasKey('users', $results['domains']['example.com']);
    }

    public function test_mx_fallback_domain_has_highest_weight(): void
    {
        $v = $this->createValidator();
        $this->scriptValidConversation($v);
        $results = $v->validate(['user@example.com']);

        $mxs = $results['domains']['example.com']['mxs'];
        $keys = array_keys($mxs);
        // Domain itself should be last (fallback per RFC 5321)
        $this->assertSame('example.com', end($keys));
        // Weight should be higher than all MX records
        $mxWeights = $mxs;
        unset($mxWeights['example.com']);
        if (!empty($mxWeights)) {
            $this->assertGreaterThan(max($mxWeights), $mxs['example.com']);
        }
    }

    public function test_noop_between_rcpts_when_enabled(): void
    {
        $v = $this->createValidator();
        $v->noop = true;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n");    // MAIL FROM
        $v->queueResponse("250 OK\r\n");    // NOOP (always, pre-catchall)
        $v->queueResponse("250 OK\r\n");    // NOOP (noop=true, once before loop)
        $v->queueResponse("250 OK\r\n");    // RCPT TO alice
        $v->queueResponse("250 OK\r\n");    // NOOP (after alice)
        $v->queueResponse("550 No such user\r\n"); // RCPT TO bob
        $v->queueResponse("250 OK\r\n");    // NOOP (after bob)
        $v->queueResponse("250 OK\r\n");    // RSET
        $v->queueResponse("221 Bye\r\n");   // QUIT

        $results = $v->validate(['alice@example.com', 'bob@example.com']);
        $this->assertTrue($results['alice@example.com']);
        $this->assertFalse($results['bob@example.com']);
    }
}
