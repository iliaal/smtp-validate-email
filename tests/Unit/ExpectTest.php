<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_Unexpected_Response;
use SMTPValidateEmail\Tests\TestableValidator;

class ExpectTest extends TestCase
{
    private TestableValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new TestableValidator();
        $this->validator->setConnected(true);
    }

    public function test_single_expected_code(): void
    {
        $this->validator->queueResponse("250 OK\r\n");
        $text = $this->validator->exposedExpect(250);
        $this->assertStringContainsString('OK', $text);
    }

    public function test_multiple_expected_codes(): void
    {
        $this->validator->queueResponse("251 User not local\r\n");
        $text = $this->validator->exposedExpect([250, 251]);
        // sscanf('%d%s') captures only first word after code
        $this->assertStringContainsString('User', $text);
    }

    public function test_unexpected_code_throws(): void
    {
        $this->validator->queueResponse("550 No such user\r\n");
        $this->expectException(SMTP_Validate_Email_Exception_Unexpected_Response::class);
        $this->validator->exposedExpect(250);
    }

    public function test_multiline_response(): void
    {
        $this->validator->queueResponse("250-mail.example.com\r\n");
        $this->validator->queueResponse("250-SIZE 52428800\r\n");
        $this->validator->queueResponse("250 8BITMIME\r\n");
        $text = $this->validator->exposedExpect(250);
        $this->assertStringContainsString('8BITMIME', $text);
    }

    public function test_starttls_detected_in_ehlo(): void
    {
        $this->validator->queueResponse("250-mail.example.com\r\n");
        $this->validator->queueResponse("250-STARTTLS\r\n");
        $this->validator->queueResponse("250 OK\r\n");
        $this->validator->exposedExpect(250);

        $tls = $this->validator->getProperty('tls');
        $this->assertTrue($tls);
    }

    public function test_service_unavailable_throws_when_not_expected(): void
    {
        $this->validator->queueResponse("421 Service not available\r\n");
        $this->expectException(SMTP_Validate_Email_Exception_Unexpected_Response::class);
        $this->validator->exposedExpect(250);
    }

    public function test_service_unavailable_accepted_when_expected(): void
    {
        $this->validator->queueResponse("421 Service not available\r\n");
        $text = $this->validator->exposedExpect([250, 421]);
        // sscanf('%d%s') captures only first word after code
        $this->assertStringContainsString('Service', $text);
    }

    public function test_empty_response_allowed(): void
    {
        $this->validator->queueResponse("999 Weird\r\n");
        $text = $this->validator->exposedExpect(250, null, true);
        $this->assertStringContainsString('Weird', $text);
    }
}
