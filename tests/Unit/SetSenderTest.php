<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class SetSenderTest extends TestCase
{
    public function test_sets_from_user_and_domain(): void
    {
        $validator = new TestableValidator();
        $validator->set_sender('alice@example.com');
        $this->assertSame('alice', $validator->getProperty('from_user'));
        $this->assertSame('example.com', $validator->getProperty('from_domain'));
    }

    public function test_plus_tag_preserved(): void
    {
        $validator = new TestableValidator();
        $validator->set_sender('alice+tag@example.com');
        $this->assertSame('alice+tag', $validator->getProperty('from_user'));
    }

    public function test_defaults(): void
    {
        $validator = new TestableValidator();
        $this->assertSame('user', $validator->getProperty('from_user'));
        $this->assertSame('localhost', $validator->getProperty('from_domain'));
    }
}
