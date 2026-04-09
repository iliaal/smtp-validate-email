<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class SetEmailsTest extends TestCase
{
    private TestableValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TestableValidator();
    }

    public function test_single_email(): void
    {
        $this->validator->set_emails(['user@example.com']);
        $domains = $this->validator->getProperty('domains');
        $this->assertSame(['example.com' => ['user']], $domains);
    }

    public function test_multiple_emails_same_domain(): void
    {
        $this->validator->set_emails(['alice@example.com', 'bob@example.com']);
        $domains = $this->validator->getProperty('domains');
        $this->assertSame(['example.com' => ['alice', 'bob']], $domains);
    }

    public function test_multiple_domains(): void
    {
        $this->validator->set_emails(['alice@foo.com', 'bob@bar.com']);
        $domains = $this->validator->getProperty('domains');
        $this->assertArrayHasKey('foo.com', $domains);
        $this->assertArrayHasKey('bar.com', $domains);
        $this->assertSame(['alice'], $domains['foo.com']);
        $this->assertSame(['bob'], $domains['bar.com']);
    }

    public function test_string_input_cast_to_array(): void
    {
        $this->validator->set_emails('user@example.com');
        $domains = $this->validator->getProperty('domains');
        $this->assertSame(['example.com' => ['user']], $domains);
    }

    public function test_duplicate_emails_are_deduplicated(): void
    {
        $this->validator->set_emails(['user@example.com', 'user@example.com']);
        $domains = $this->validator->getProperty('domains');
        $this->assertSame(['example.com' => ['user']], $domains);
    }

    public function test_invalid_email_format_skipped(): void
    {
        $this->validator->set_emails(['valid@example.com', 'not-an-email', '@missing-user.com', 'noat']);
        $domains = $this->validator->getProperty('domains');
        $this->assertSame(['example.com' => ['valid']], $domains);
    }

    public function test_replaces_previous_emails(): void
    {
        $this->validator->set_emails(['old@example.com']);
        $this->validator->set_emails(['new@other.com']);
        $domains = $this->validator->getProperty('domains');
        $this->assertArrayNotHasKey('example.com', $domains);
        $this->assertSame(['other.com' => ['new']], $domains);
    }
}
