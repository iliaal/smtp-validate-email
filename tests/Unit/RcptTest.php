<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Mail_From;
use SMTPValidateEmail\Tests\TestableValidator;

class RcptTest extends TestCase
{
    private TestableValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TestableValidator();
        $this->validator->setupSmtpSession();
    }

    public function test_valid_recipient_250(): void
    {
        $this->validator->queueResponse("250 OK\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertTrue($result);
    }

    public function test_valid_recipient_251(): void
    {
        $this->validator->queueResponse("251 User not local\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertTrue($result);
    }

    public function test_invalid_recipient_550(): void
    {
        $this->validator->queueResponse("550 No such user\r\n");
        $result = $this->validator->exposedRcpt('bogus@example.com');
        $this->assertFalse($result);
    }

    public function test_greylisted_450_when_considered_valid(): void
    {
        $this->validator->greylisted_considered_valid = true;
        $this->validator->queueResponse("450 Try again later\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertTrue($result);
    }

    public function test_greylisted_450_when_not_considered_valid(): void
    {
        $this->validator->greylisted_considered_valid = false;
        $this->validator->queueResponse("450 Try again later\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertFalse($result);
    }

    public function test_greylisted_451(): void
    {
        $this->validator->greylisted_considered_valid = true;
        $this->validator->queueResponse("451 Action aborted\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertTrue($result);
    }

    public function test_greylisted_452(): void
    {
        $this->validator->greylisted_considered_valid = true;
        $this->validator->queueResponse("452 Insufficient storage\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertTrue($result);
    }

    public function test_ip_reverse_lookup_rejected_returns_false(): void
    {
        // BUG #2: The code has a workaround checking for "IP reverse lookup rejected"
        // in the exception message, but it's dead code. The expect() regex strips "IP"
        // from the message before the exception is thrown, so the stripos check never
        // matches. The workaround intended to return true, but actually returns false.
        // We'll remove the dead code in the fix phase.
        $this->validator->no_comm_is_valid = false;
        $this->validator->queueResponse("550 IP reverse lookup rejected\r\n");
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertFalse($result);
    }

    public function test_not_connected_returns_null(): void
    {
        $this->validator->setConnected(false);
        $result = $this->validator->exposedRcpt('user@example.com');
        $this->assertNull($result);
    }

    public function test_no_mail_from_throws(): void
    {
        $fresh = new TestableValidator();
        $fresh->setConnected(true);
        // Set helo state but not mail state
        $fresh->queueResponse("220 mail.example.com\r\n");
        $fresh->queueResponse("250 OK\r\n");
        $fresh->exposedHelo();

        $this->expectException(SMTP_Validate_Email_Exception_No_Mail_From::class);
        $fresh->exposedRcpt('user@example.com');
    }

    public function test_error_message_stored_in_results(): void
    {
        $this->validator->queueResponse("550 Mailbox not found\r\n");
        $this->validator->exposedRcpt('bogus@example.com');

        $results = $this->validator->get_results(false);
        $this->assertArrayHasKey('bogus@example.com_error_msg', $results);
    }
}
