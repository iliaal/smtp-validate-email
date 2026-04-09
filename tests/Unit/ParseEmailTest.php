<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class ParseEmailTest extends TestCase
{
    private TestableValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TestableValidator();
    }

    public function test_simple_email(): void
    {
        $result = $this->validator->exposedParseEmail('user@example.com');
        $this->assertSame(['user', 'example.com'], $result);
    }

    public function test_email_with_plus_tag(): void
    {
        $result = $this->validator->exposedParseEmail('user+tag@example.com');
        $this->assertSame(['user+tag', 'example.com'], $result);
    }

    public function test_email_with_subdomain(): void
    {
        $result = $this->validator->exposedParseEmail('user@mail.example.com');
        $this->assertSame(['user', 'mail.example.com'], $result);
    }

    public function test_email_with_dots_in_local(): void
    {
        $result = $this->validator->exposedParseEmail('first.last@example.com');
        $this->assertSame(['first.last', 'example.com'], $result);
    }

    public function test_email_with_multiple_at_signs(): void
    {
        $result = $this->validator->exposedParseEmail('user@name@example.com');
        $this->assertSame(['user@name', 'example.com'], $result);
    }

    public function test_no_at_sign(): void
    {
        $result = $this->validator->exposedParseEmail('nodomain');
        $this->assertSame(['', 'nodomain'], $result);
    }

    public function test_empty_local_part(): void
    {
        $result = $this->validator->exposedParseEmail('@example.com');
        $this->assertSame(['', 'example.com'], $result);
    }

    public function test_empty_string(): void
    {
        $result = $this->validator->exposedParseEmail('');
        $this->assertSame(['', ''], $result);
    }
}
