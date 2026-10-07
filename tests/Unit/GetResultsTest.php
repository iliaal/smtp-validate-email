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

    public function test_domain_info_can_be_toggled_without_changing_cached_results(): void
    {
        $validator = new TestableValidator();
        $validator->queueResponse(
            "220 mail.example.com ESMTP\r\n",
            "250 mail.example.com\r\n",
            "250 OK\r\n", // MAIL FROM
            "250 OK\r\n", // NOOP
            "250 OK\r\n", // RCPT TO
            "250 OK\r\n", // RSET
            "221 Bye\r\n"  // QUIT
        );

        $withDomains = $validator->validate(['user@example.com', 'not-an-email']);
        $expected = $withDomains;
        unset($expected['domains']);
        $commands = $validator->sentCommands;

        $this->assertTrue($expected['user@example.com']);
        $this->assertFalse($expected['not-an-email']);
        $this->assertSame('Invalid email format', $expected['not-an-email_error_msg']);
        $this->assertSame($expected, $validator->get_results(false));
        $this->assertSame($withDomains, $validator->get_results(true));
        $this->assertSame($expected, $validator->get_results(false));
        $this->assertSame($expected, $validator->getProperty('results'));
        $this->assertSame($commands, $validator->sentCommands);
    }

    public function test_empty_results_can_exclude_previously_requested_domain_info(): void
    {
        $validator = new TestableValidator();

        $this->assertSame(['domains' => []], $validator->get_results());
        $this->assertSame([], $validator->get_results(false));
        $this->assertSame(['domains' => []], $validator->get_results());
    }
}
