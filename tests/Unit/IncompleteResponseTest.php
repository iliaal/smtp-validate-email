<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTPValidateEmail;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Response;

class IncompleteResponseTest extends TestCase
{
    public static function incompleteResponses(): array
    {
        return [
            'code only' => ['250'],
            'text without newline' => ['250 OK'],
            'carriage return only' => ["250 OK\r"],
            'multiple read chunks' => ['250 ' . str_repeat('X', 5000)],
        ];
    }

    #[DataProvider('incompleteResponses')]
    public function test_eof_during_response_is_a_communication_failure(string $response): void
    {
        $validator = $this->validatorWithResponse($response);
        $expect = new \ReflectionMethod(SMTPValidateEmail::class, 'expect');
        try {
            $expect->invoke($validator, 250, 1);
            $this->fail('An incomplete success reply must not be accepted');
        } catch (SMTP_Validate_Email_Exception_No_Response $e) {
            $this->assertSame('Incomplete response in recv', $e->getMessage());
            $socket = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
            $this->assertNull($socket->getValue($validator));
        }
    }

    public function test_complete_long_response_before_eof_is_still_accepted(): void
    {
        $response = '250 ' . str_repeat('X', 5000) . "\r\n";
        $validator = $this->validatorWithResponse($response);
        $recv = new \ReflectionMethod(SMTPValidateEmail::class, 'recv');
        $this->assertSame($response, $recv->invoke($validator, 1));
    }

    public static function communicationPolicies(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('communicationPolicies')]
    public function test_partial_rcpt_reply_uses_policy_and_continues_batch(bool $policy): void
    {
        $validator = new class extends SMTPValidateEmail {
            protected function mx_query($domain): array
            {
                return [[$domain], [10]];
            }

            protected function connect($host): void
            {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                $response = "220 Ready\r\n250 Hello\r\n250 Sender OK\r\n250 NOOP OK\r\n";
                $response .= $host === 'broken.test' ? '250 Recipient OK' : "250 Recipient OK\r\n250 Reset\r\n221 Bye\r\n";
                fwrite($pair[1], $response);
                fclose($pair[1]);
                $socket = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
                $socket->setValue($this, $pair[0]);
            }

            protected function send($cmd): int
            {
                // The peer's replies are preloaded; exercise production recv/expect.
                return strlen($cmd);
            }
        };
        $validator->no_comm_is_valid = $policy;
        $results = $validator->validate(['first@broken.test', 'second@broken.test', 'ok@healthy.test']);

        foreach (['first', 'second'] as $user) {
            $this->assertSame($policy, $results[$user . '@broken.test']);
            $this->assertSame('Incomplete response in recv', $results[$user . '@broken.test_error_msg']);
        }
        $this->assertTrue($results['ok@healthy.test']);
        $this->assertArrayNotHasKey('ok@healthy.test_error_msg', $results);
    }

    private function validatorWithResponse(string $response): SMTPValidateEmail
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($pair[1], $response);
        fclose($pair[1]);
        $validator = new SMTPValidateEmail();
        $socket = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $socket->setValue($validator, $pair[0]);
        return $validator;
    }
}
