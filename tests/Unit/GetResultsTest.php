<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class GetResultsTest extends TestCase
{
    public function test_empty_results_with_domains(): void
    {
        $validator = new TestableValidator();
        $results = $validator->get_results(true);
        $this->assertArrayHasKey('domains', $results);
    }

    public function test_empty_results_without_domains(): void
    {
        $validator = new TestableValidator();
        $results = $validator->get_results(false);
        $this->assertArrayNotHasKey('domains', $results);
    }

    public function test_validate_returns_results_with_domains_by_default(): void
    {
        $validator = new TestableValidator();
        $validator->set_sender('test@localhost');
        $validator->set_emails(['user@example.com']);

        // Script a full valid conversation
        $validator->queueResponse("220 mail.example.com ESMTP\r\n");
        $validator->queueResponse("250 mail.example.com\r\n");
        $validator->queueResponse("250 OK\r\n");    // MAIL FROM
        $validator->queueResponse("250 OK\r\n");    // NOOP
        $validator->queueResponse("250 OK\r\n");    // RCPT TO
        $validator->queueResponse("250 OK\r\n");    // RSET
        $validator->queueResponse("221 Bye\r\n");   // QUIT

        $results = $validator->validate();
        $this->assertArrayHasKey('domains', $results);
        $this->assertArrayHasKey('example.com', $results['domains']);
    }

    public function test_validate_excludes_domains_when_requested(): void
    {
        $validator = new TestableValidator();
        $validator->set_sender('test@localhost');
        $validator->set_emails(['user@example.com']);

        $validator->queueResponse("220 mail.example.com ESMTP\r\n");
        $validator->queueResponse("250 mail.example.com\r\n");
        $validator->queueResponse("250 OK\r\n");
        $validator->queueResponse("250 OK\r\n");
        $validator->queueResponse("250 OK\r\n");
        $validator->queueResponse("250 OK\r\n");
        $validator->queueResponse("221 Bye\r\n");

        $results = $validator->validate([], '', false);
        $this->assertArrayNotHasKey('domains', $results);
    }
}
