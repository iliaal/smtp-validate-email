<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTPValidateEmail;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Mail_From;
use SMTPValidateEmail\Tests\TestableValidator;

/**
 * Regression tests for the 2026-08 full-codebase review cycle.
 */
class Cycle20260821FixesTest extends TestCase
{
    /** STARTTLS in the greeting banner must not trigger a spurious STARTTLS */
    public function test_starttls_not_sent_when_only_banner_mentions_it(): void
    {
        $v = new TestableValidator();
        $v->setConnected(true);
        $v->set_sender('from@example.com');
        // Greeting mentions STARTTLS; EHLO response does NOT advertise it
        $v->queueResponse("220 mx.test ESMTP STARTTLS-ready\r\n");
        $v->queueResponse("250-mail.test\r\n");
        $v->queueResponse("250 OK\r\n");

        $this->assertTrue($v->exposedHelo());

        $sent = implode('|', $v->sentCommands);
        $this->assertStringContainsString('EHLO', $sent);
        $this->assertStringNotContainsString('STARTTLS', $sent);
        $this->assertFalse($v->getProperty('tls'));
    }

    /** STARTTLS still attempted when EHLO genuinely advertises it */
    public function test_starttls_still_detected_from_ehlo_advertisement(): void
    {
        $v = new TestableValidator();
        $v->setConnected(true);
        $v->queueResponse("220 mx.test ESMTP\r\n");
        $v->queueResponse("250-mail.test\r\n");
        $v->queueResponse("250-STARTTLS\r\n");
        $v->queueResponse("250 OK\r\n");

        $v->exposedHelo();
        // crypto negotiation on a mock socket fails -> No_TLS, but the command
        // must have been sent because EHLO advertised it
        $sent = implode('|', $v->sentCommands);
        $this->assertStringContainsString('STARTTLS', $sent);
    }

    /** A library exception escaping rcpt() no longer aborts remaining domains */
    public function test_validate_catches_no_mail_from_and_continues_batch(): void
    {
        $v = new class extends TestableValidator {
            private bool $thrown = false;
            protected function rcpt($to)
            {
                // throw only for the first domain; second domain must succeed
                if (!$this->thrown) {
                    $this->thrown = true;
                    throw new SMTP_Validate_Email_Exception_No_Mail_From('boom');
                }
                $this->queueResponse("250 OK\r\n");
                return parent::rcpt($to);
            }
        };
        $v->setConnected(true);
        $v->set_sender('from@example.com');
        // domain foo.com
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n"); // MAIL FROM
        $v->queueResponse("250 OK\r\n"); // NOOP
        // domain bar.com
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n"); // MAIL FROM
        $v->queueResponse("250 OK\r\n"); // NOOP
        // RCPT response queued by the rcpt() override
        $v->queueResponse("250 OK\r\n"); // RSET
        $v->queueResponse("221 Bye\r\n"); // QUIT

        $results = $v->validate(['a@foo.com', 'b@bar.com']);

        $this->assertFalse($results['a@foo.com']);
        $this->assertSame('boom', $results['a@foo.com_error_msg']);
        $this->assertTrue($results['b@bar.com']); // second domain fully processed
    }

    /** Domain-level results carry no _error_msg key when there is no error */
    public function test_no_null_error_msg_keys(): void
    {
        $v = new TestableValidator();
        $v->set_sender('from@example.com');
        $v->catchall_test = true;
        $v->catchall_is_valid = false;
        $v->queueResponse("220 mail.example.com ESMTP\r\n");
        $v->queueResponse("250 mail.example.com\r\n");
        $v->queueResponse("250 OK\r\n"); // MAIL FROM
        $v->queueResponse("250 OK\r\n"); // NOOP
        $v->queueResponse("250 OK\r\n"); // catch-all probe accepted

        $results = $v->validate(['user@example.com']);

        $this->assertFalse($results['user@example.com']);
        $this->assertArrayNotHasKey('user@example.com_error_msg', $results);
    }

    /** recv() assembles response lines longer than the 4096-byte fgets buffer */
    public function test_recv_assembles_long_lines(): void
    {
        $real = new class extends SMTPValidateEmail {
            public function tryRecv(int $timeout = 5): string
            {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
                $ref->setValue($this, $pair[0]);
                fwrite($pair[1], '550 ' . str_repeat('x', 5000) . "\r\n250 next\r\n");
                $m = new \ReflectionMethod(SMTPValidateEmail::class, 'recv');
                try {
                    return $m->invoke($this, $timeout);
                } finally {
                    fclose($pair[0]);
                    fclose($pair[1]);
                }
            }
        };

        $line = $real->tryRecv();
        $this->assertGreaterThanOrEqual(5000, strlen($line));
        $this->assertTrue(substr($line, -1) === "\n");
        $this->assertStringStartsWith('550 ', $line);
        // The next line must NOT have been consumed into this one
        $this->assertStringNotContainsString('250 next', $line);
    }

    /** send() writes the complete command and returns the full byte count */
    public function test_send_writes_complete_command(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $real = new SMTPValidateEmail();
        $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $ref->setValue($real, $pair[0]);

        $cmd = 'MAIL FROM:<user@example.com>';
        $m = new \ReflectionMethod(SMTPValidateEmail::class, 'send');
        $written = $m->invoke($real, $cmd);

        $this->assertSame(strlen($cmd . "\r\n"), $written);
        $onWire = fread($pair[1], strlen($cmd) + 2);
        $this->assertSame($cmd . "\r\n", $onWire);

        fclose($pair[0]);
        fclose($pair[1]);
    }
}
