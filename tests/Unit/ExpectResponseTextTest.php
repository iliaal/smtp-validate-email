<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_Unexpected_Response;

class ExpectResponseTextTest extends TestCase
{
    public static function replies(): array
    {
        return [
            'single word' => [["250 OK\r\n"], 250, false, 'OK'],
            'two words' => [["250 Mailbox accepted\r\n"], 250, false, 'Mailbox accepted'],
            'enhanced status' => [["250 2.1.5 Recipient accepted\r\n"], 250, false, '2.1.5 Recipient accepted'],
            'punctuation' => [["250 user@example.com: accepted.\r\n"], 250, false, 'user@example.com: accepted.'],
            'multiple spaces' => [["250   Mailbox  accepted  \r\n"], 250, false, 'Mailbox  accepted  '],
            'tabs' => [["250\tMailbox\taccepted\r\n"], 250, false, "Mailbox\taccepted"],
            'lf ending' => [["250 Mailbox accepted\n"], 250, false, 'Mailbox accepted'],
            'empty with space' => [["250 \r\n"], 250, false, ''],
            'empty without space' => [["250\r\n"], 250, false, ''],
            'empty with tabs' => [["250\t \t\r\n"], 250, false, ''],
            'final line only' => [["250-First line\r\n", "250-Second line\r\n", "250 Final line\r\n"], 250, false, 'Final line'],
            'empty final line' => [["250-First line\r\n", "250 \r\n"], 250, false, ''],
            'accepted alternate code' => [["251 User not local\r\n"], [250, 251], false, 'User not local'],
            'expected unavailable' => [["421 Service not available\r\n"], [250, 421], false, 'Service not available'],
            'permitted unexpected code' => [["999 Weird server reply\r\n"], 250, true, 'Weird server reply'],
            // Preserve permissive numeric parsing and missing-code handling.
            'no separator' => [["250Accepted anyway\r\n"], 250, false, 'Accepted anyway'],
            'leading whitespace' => [[" \t250 Accepted anyway\r\n"], 250, false, 'Accepted anyway'],
            'vertical whitespace' => [["250\vMailbox\faccepted\r\n"], 250, false, "Mailbox\faccepted"],
            'one character' => [["250 X\r\n"], 250, false, 'X'],
            'missing code allowed' => [["SMTP nonstandard reply\r\n"], 250, true, "SMTP nonstandard reply\r\n"],
        ];
    }

    public static function unexpectedReplies(): array
    {
        return [
            ["550 No such user\r\n", false, 'such user'],
            ["550 5.1.1 No such user\r\n", false, 'No such user'],
            ["421 Service not available\r\n", false, 'not available'],
            ["421 Service not available\r\n", true, 'not available'],
            ["SMTP nonstandard reply\r\n", false, "SMTP nonstandard reply\r\n"],
        ];
    }

    #[DataProvider('unexpectedReplies')]
    public function test_unexpected_response_diagnostics_are_unchanged(string $reply, bool $allowUnexpected, string $expected): void
    {
        $validator = new TestableValidator();
        $validator->setConnected(true);
        $validator->queueResponse($reply);
        try {
            $validator->exposedExpect(250, null, $allowUnexpected);
            $this->fail('Expected an unexpected-response exception');
        } catch (SMTP_Validate_Email_Exception_Unexpected_Response $exception) {
            $this->assertSame($expected, $exception->getMessage());
        }
    }

    #[DataProvider('replies')]
    public function test_complete_final_response_text(array $lines, int|array $codes, bool $allowUnexpected, string $expected): void
    {
        $validator = new TestableValidator();
        $validator->setConnected(true);
        $validator->queueResponse(...$lines);
        $this->assertSame($expected, $validator->exposedExpect($codes, null, $allowUnexpected));
    }
}
