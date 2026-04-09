<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class CatchAllTest extends TestCase
{
    private TestableValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TestableValidator();
        $this->validator->setupSmtpSession();
    }

    public function test_disabled_returns_false(): void
    {
        $this->validator->catchall_test = false;
        $result = $this->validator->accepts_any_recipient('example.com');
        $this->assertFalse($result);
    }

    public function test_catch_all_detected(): void
    {
        $this->validator->catchall_test = true;
        // 250 to random address means catch-all
        $this->validator->queueResponse("250 OK\r\n");
        $result = $this->validator->accepts_any_recipient('example.com');
        $this->assertTrue($result);
    }

    public function test_no_catch_all(): void
    {
        $this->validator->catchall_test = true;
        // 550 to random address means no catch-all
        $this->validator->queueResponse("550 No such user\r\n");
        // NOOP response
        $this->validator->queueResponse("250 OK\r\n");
        $result = $this->validator->accepts_any_recipient('example.com');
        $this->assertFalse($result);
    }

    public function test_uses_random_address(): void
    {
        $this->validator->catchall_test = true;
        $this->validator->queueResponse("550 No such user\r\n");
        $this->validator->queueResponse("250 OK\r\n"); // NOOP
        $this->validator->accepts_any_recipient('example.com');

        $rcptCmd = $this->validator->sentCommands[0];
        $this->assertStringContainsString('RCPT TO:<catch-all-test-', $rcptCmd);
        // Should contain hex random bytes, not a unix timestamp
        $this->assertMatchesRegularExpression('/catch-all-test-[0-9a-f]{16}@/', $rcptCmd);
    }
}
