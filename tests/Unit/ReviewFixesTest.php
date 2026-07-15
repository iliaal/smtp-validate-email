<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Response;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_Timeout;
use SMTPValidateEmail\SMTPValidateEmail;
use SMTPValidateEmail\Tests\TestableValidator;

/**
 * Regression tests for issues found in the library-wide code review.
 */
class ReviewFixesTest extends TestCase
{
    private function scriptValidConversation(TestableValidator $v): void
    {
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->queueResponse("221 Bye\r\n");
    }

    /** CR-003: constructor emails + validate() without re-passing the list keeps format rejects */
    public function test_constructor_path_preserves_invalid_format_results(): void
    {
        $v = new TestableValidator(['not-an-email', 'user@example.com'], 'from@example.com');
        $this->scriptValidConversation($v);

        $results = $v->validate();

        $this->assertFalse($results['not-an-email']);
        $this->assertSame('Invalid email format', $results['not-an-email_error_msg']);
        $this->assertTrue($results['user@example.com']);
    }

    /** CR-010: domains_info does not leak across validate() calls */
    public function test_domains_info_reset_between_validate_calls(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $this->scriptValidConversation($v);
        $first = $v->validate(['a@dom1.com']);
        $this->assertArrayHasKey('dom1.com', $first['domains']);

        $this->scriptValidConversation($v);
        $second = $v->validate(['b@dom2.com']);
        $this->assertArrayHasKey('dom2.com', $second['domains']);
        $this->assertArrayNotHasKey('dom1.com', $second['domains']);
    }

    /** CR-001: expect() rethrows No_Response instead of returning success */
    public function test_expect_no_response_rethrows(): void
    {
        $v = new TestableValidator();
        $v->setConnected(true);

        $this->expectException(SMTP_Validate_Email_Exception_No_Response::class);
        $v->exposedExpect(250);
    }

    /** CR-001: peer drop during MAIL FROM is not treated as valid session */
    public function test_no_response_during_mail_does_not_mark_valid(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->no_comm_is_valid = false;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        // MAIL FROM: no response (empty queue → No_Response)

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
    }

    /** CR-002: set_sender rejects control characters / invalid format */
    public function test_set_sender_rejects_crlf_injection(): void
    {
        $v = new TestableValidator();
        $this->expectException(\InvalidArgumentException::class);
        $v->set_sender("x>\r\nRCPT TO:<victim@target.com>\r\n@evil.com");
    }

    public function test_set_sender_rejects_non_email(): void
    {
        $v = new TestableValidator();
        $this->expectException(\InvalidArgumentException::class);
        $v->set_sender('not-an-email');
    }

    public function test_set_sender_allows_localhost(): void
    {
        $v = new TestableValidator();
        $v->set_sender('test@localhost');
        $this->assertSame('test', $v->getProperty('from_user'));
        $this->assertSame('localhost', $v->getProperty('from_domain'));
    }

    /** CR-002: send() rejects CR/LF even if it somehow reached the wire path */
    public function test_send_rejects_control_characters(): void
    {
        $v = new TestableValidator();
        $v->setConnected(true);
        $send = new \ReflectionMethod(SMTPValidateEmail::class, 'send');
        // Use a subclass that calls parent::send with a real socket? TestableValidator overrides send.
        // Exercise TestableValidator's mirrored guard (same rule as production).
        $this->expectException(\SMTPValidateEmail\SMTP_Validate_Email_Exception::class);
        $v->sentCommands = [];
        // Invoke protected send via a thin subclass of the production class
        $real = new class extends SMTPValidateEmail {
            public function trySend(string $cmd): void
            {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
                $ref->setValue($this, $pair[0]);
                try {
                    $m = new \ReflectionMethod(SMTPValidateEmail::class, 'send');
                    $m->invoke($this, $cmd);
                } finally {
                    fclose($pair[0]);
                    fclose($pair[1]);
                }
            }
        };
        $real->trySend("MAIL FROM:<x>\r\nRCPT TO:<y@z.com>");
    }

    /** CR-006: TLS capability does not stick across sessions */
    public function test_tls_flag_reset_on_disconnect(): void
    {
        $v = new TestableValidator();
        $v->setConnected(true);
        $v->queueResponse("250-STARTTLS\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->exposedExpect(250);
        $this->assertTrue($v->getProperty('tls'));

        $v->setConnected(true);
        $disconnect = new \ReflectionMethod(TestableValidator::class, 'disconnect');
        $disconnect->invoke($v, false);
        $this->assertFalse($v->getProperty('tls'));
    }

    /** CR-015: final-line STARTTLS advertisement is detected */
    public function test_starttls_detected_on_final_ehlo_line(): void
    {
        $v = new TestableValidator();
        $v->setConnected(true);
        $v->queueResponse("250 STARTTLS\r\n");
        $v->exposedExpect(250);
        $this->assertTrue($v->getProperty('tls'));
    }

    /** CR-004: RCPT timeout uses no_comm_is_valid, not "invalid mailbox" */
    public function test_rcpt_timeout_uses_no_comm_policy(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->no_comm_is_valid = true;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n"); // MAIL FROM
        $v->queueResponse("250 OK\r\n"); // NOOP
        // RCPT: trigger timeout instead of a reply
        $v->throwTimeoutOnRecv = true;

        $results = $v->validate(['user@example.com']);
        $this->assertTrue($results['user@example.com']);
        $this->assertSame('Connection timeout', $results['user@example.com_error_msg']);
    }

    public function test_rcpt_timeout_default_is_invalid_via_no_comm(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->no_comm_is_valid = false;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->queueResponse("250 OK\r\n");
        $v->throwTimeoutOnRecv = true;

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
        $this->assertSame('Connection timeout', $results['user@example.com_error_msg']);
    }

    /** CR-005: late session error after RCPT does not wipe good per-user results */
    public function test_late_error_preserves_rcpt_results(): void
    {
        $v = new class extends TestableValidator {
            private int $rsetCalls = 0;

            protected function rset(): void
            {
                $this->rsetCalls++;
                throw new SMTP_Validate_Email_Exception_Timeout('Timed out in rset');
            }
        };
        $v->set_sender('from@example.com');
        $v->no_comm_is_valid = false;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n"); // MAIL FROM
        $v->queueResponse("250 OK\r\n"); // NOOP
        $v->queueResponse("250 OK\r\n"); // RCPT alice
        $v->queueResponse("550 No such user\r\n"); // RCPT bob
        // rset throws Timeout

        $results = $v->validate(['alice@example.com', 'bob@example.com']);
        $this->assertTrue($results['alice@example.com']);
        $this->assertFalse($results['bob@example.com']);
    }

    /** CR-008: failed MX does not leave sticky error_msg after a later MX succeeds */
    public function test_mx_failover_clears_connection_error_on_success(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->mxQueryResults = [[['mx-bad.example.com', 'mx-good.example.com'], [10, 20]]];
        $v->failConnectHosts = ['mx-bad.example.com'];
        // Domain fallback also in mxs — fail it too so only mx-good works... actually
        // code adds domain as fallback. Fail domain host as well for clean path.
        $v->failConnectHosts = ['mx-bad.example.com', 'example.com'];
        $this->scriptValidConversation($v);

        $results = $v->validate(['user@example.com']);
        $this->assertTrue($results['user@example.com']);
        $this->assertArrayNotHasKey('user@example.com_error_msg', $results);
    }

    /** CR-008: all MX fail → no_conn policy + error once */
    public function test_all_mx_fail_sets_no_conn_result(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->no_conn_is_valid = false;
        $v->mxQueryResults = [[['mx1.example.com'], [10]]];
        $v->failConnectHosts = ['mx1.example.com', 'example.com'];

        $results = $v->validate(['user@example.com']);
        $this->assertFalse($results['user@example.com']);
        $this->assertArrayHasKey('user@example.com_error_msg', $results);
        $this->assertNotNull($results['user@example.com_error_msg']);
    }

    /** Multi-domain batch: both domains get results; no state leak */
    public function test_multi_domain_validate(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        // domain foo
        $this->scriptValidConversation($v);
        // domain bar
        $this->scriptValidConversation($v);

        $results = $v->validate(['a@foo.com', 'b@bar.com']);
        $this->assertTrue($results['a@foo.com']);
        $this->assertTrue($results['b@bar.com']);
        $this->assertArrayHasKey('foo.com', $results['domains']);
        $this->assertArrayHasKey('bar.com', $results['domains']);
    }

    /** CR-013: log is cleared at the start of each validate() */
    public function test_log_cleared_at_validate_start(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->log = ['stale entry'];
        $this->scriptValidConversation($v);
        $v->validate(['user@example.com']);
        $this->assertNotContains('stale entry', $v->get_log());
    }
}
