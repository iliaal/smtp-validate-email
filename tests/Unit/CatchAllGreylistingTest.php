<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_Timeout;
use SMTPValidateEmail\Tests\TestableValidator;

class CatchAllGreylistingTest extends TestCase
{
    public static function temporaryReplies(): array
    {
        $cases = [];
        foreach ([450, 451, 452] as $code) {
            foreach ([false, true] as $policy) {
                $cases["$code, greylisting " . ($policy ? 'valid' : 'invalid')] = [$code, $policy];
            }
        }
        return $cases;
    }

    #[DataProvider('temporaryReplies')]
    public function test_temporary_probe_reply_does_not_reject_the_domain(int $code, bool $policy): void
    {
        $validator = new TestableValidator();
        $validator->catchall_test = true;
        $validator->catchall_is_valid = false;
        $validator->greylisted_considered_valid = $policy;
        $validator->queueResponse(
            "220 Ready\r\n", "250 Hello\r\n", "250 Sender OK\r\n", "250 NOOP\r\n",
            "$code Try later\r\n", "250 NOOP\r\n", // Random catch-all probe and its NOOP.
            "250 Recipient OK\r\n", "$code Try later\r\n", // Real recipients.
            "250 Reset\r\n", "221 Bye\r\n"
        );
        $results = $validator->validate(['alice@example.com', 'bob@example.com']);

        $this->assertTrue($results['alice@example.com']);
        $this->assertSame($policy, $results['bob@example.com']);
        $this->assertArrayNotHasKey('catchall', $results['domains']['example.com']);
        $this->assertContains('RCPT TO:<alice@example.com>', $validator->sentCommands);
        $this->assertContains('RCPT TO:<bob@example.com>', $validator->sentCommands);
        $this->assertSame($policy, $validator->greylisted_considered_valid);
    }

    public static function acceptedReplies(): array
    {
        return [[250], [251]];
    }

    #[DataProvider('acceptedReplies')]
    public function test_positive_probe_still_detects_a_catchall(int $code): void
    {
        $validator = new TestableValidator();
        $validator->catchall_test = true;
        $validator->catchall_is_valid = false;
        $validator->queueResponse(
            "220 Ready\r\n", "250 Hello\r\n", "250 Sender OK\r\n", "250 NOOP\r\n",
            "$code Accepted\r\n"
        );
        $results = $validator->validate(['alice@example.com']);

        $this->assertFalse($results['alice@example.com']);
        $this->assertTrue($results['domains']['example.com']['catchall']);
        $this->assertNotContains('RCPT TO:<alice@example.com>', $validator->sentCommands);
    }

    public function test_failed_direct_probe_restores_real_recipient_policy(): void
    {
        $validator = new TestableValidator();
        $validator->setupSmtpSession();
        $validator->catchall_test = true;
        $validator->throwTimeoutOnRecv = true;
        try {
            $validator->accepts_any_recipient('example.com');
            $this->fail('Expected the probe timeout');
        } catch (SMTP_Validate_Email_Exception_Timeout $exception) {
            $this->assertSame('Timed out in recv', $exception->getMessage());
        }
        $validator->queueResponse("450 Try later\r\n");
        $this->assertTrue($validator->exposedRcpt('alice@example.com'));
        $this->assertTrue($validator->greylisted_considered_valid);
    }
}
