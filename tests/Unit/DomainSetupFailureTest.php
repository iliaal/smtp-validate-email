<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTP_Validate_Email_Exception;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Timeout;
use SMTPValidateEmail\Tests\TestableValidator;

class DomainSetupFailureTest extends TestCase
{
    public static function failurePolicies(): array
    {
        return [
            'MX failure, invalid policy' => ['mx', false],
            'MX failure, valid policy' => ['mx', true],
            'socket setup failure, invalid policy' => ['connect', false],
            'socket setup failure, valid policy' => ['connect', true],
        ];
    }

    #[DataProvider('failurePolicies')]
    public function test_setup_failure_does_not_abort_later_domains(string $stage, bool $policy): void
    {
        $v = new class($stage) extends TestableValidator {
            public array $disconnectedHosts = [];

            public function __construct(private string $stage)
            {
                parent::__construct();
            }

            protected function mx_query($domain): array
            {
                if ($domain === 'broken.example' && $this->stage === 'mx') {
                    throw new SMTP_Validate_Email_Exception('MX lookup failed');
                }
                return parent::mx_query($domain);
            }

            protected function connect($host): void
            {
                parent::connect($host);
                if ($host === 'broken.example' && $this->stage === 'connect') {
                    throw new SMTP_Validate_Email_Exception_No_Timeout('Cannot set timeout');
                }
            }

            protected function disconnect($quit = true): void
            {
                if ($this->getProperty('connect_host') !== null) {
                    $this->disconnectedHosts[] = $this->getProperty('connect_host');
                }
                parent::disconnect($quit);
            }
        };
        $v->no_comm_is_valid = $policy;
        // Connection refusal has its own policy; setup errors must use no_comm.
        $v->no_conn_is_valid = !$policy;
        $v->queueResponse(
            "220 mail.example ESMTP\r\n", // greeting for the healthy domain
            "250 mail.example\r\n",      // EHLO
            "250 OK\r\n",                // MAIL FROM
            "250 OK\r\n",                // NOOP
            "250 OK\r\n",                // RCPT TO
            "250 OK\r\n",                // RSET
            "221 Bye\r\n"                // QUIT
        );

        $results = $v->validate(['one@broken.example', 'two@broken.example', 'ok@healthy.example']);

        $message = $stage === 'mx' ? 'MX lookup failed' : 'Cannot set timeout';
        foreach (['one', 'two'] as $user) {
            $this->assertSame($policy, $results[$user . '@broken.example']);
            $this->assertSame($message, $results[$user . '@broken.example_error_msg']);
        }
        $this->assertTrue($results['ok@healthy.example']);
        $this->assertArrayNotHasKey('ok@healthy.example_error_msg', $results);
        $this->assertNotContains('RCPT TO:<one@broken.example>', $v->sentCommands);
        $this->assertNotContains('RCPT TO:<two@broken.example>', $v->sentCommands);
        $this->assertContains('RCPT TO:<ok@healthy.example>', $v->sentCommands);
        if ($stage === 'connect') {
            $this->assertSame('broken.example', $v->disconnectedHosts[0]);
        }
    }
    public function test_unrelated_errors_are_not_swallowed(): void
    {
        $v = new class extends TestableValidator {
            protected function mx_query($domain): array
            {
                throw new \LogicException('Invalid resolver configuration');
            }
        };

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid resolver configuration');
        $v->validate(['user@broken.example']);
    }

}
