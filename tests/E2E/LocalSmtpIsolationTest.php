<?php

namespace SMTPValidateEmail\Tests\E2E;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Connection;
use SMTPValidateEmail\Tests\E2E\LocalSmtpValidator;

#[Group('e2e')]
class LocalSmtpIsolationTest extends TestCase
{
    public static function otherLoopbackHosts(): array
    {
        // Even with the guard removed, regression tests only attempt loopback.
        return [['127.0.0.2'], ['[::1]']];
    }

    #[DataProvider('otherLoopbackHosts')]
    public function test_non_fixture_hosts_are_rejected_before_connecting(string $host): void
    {
        $validator = new class extends LocalSmtpValidator {
            public function connectForTest(string $host): void
            {
                $this->connect($host);
            }
        };
        $this->expectException(SMTP_Validate_Email_Exception_No_Connection::class);
        $this->expectExceptionMessage('Local SMTP fixture refuses non-fixture host: ' . $host);
        try {
            $validator->connectForTest($host);
        } finally {
            $this->assertSame([], $validator->get_log());
        }
    }

    public function test_fixture_host_can_still_connect(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($listener);
        try {
            $validator = new class extends LocalSmtpValidator {
                public function connectForTest(): bool
                {
                    $this->connect('127.0.0.1');
                    return $this->connected();
                }
            };
            $address = stream_socket_get_name($listener, false);
            $validator->connect_port = (int)substr(strrchr($address, ':'), 1);
            $this->assertTrue($validator->connectForTest());
            unset($validator);
        } finally {
            fclose($listener);
        }
    }

    public function test_failed_fixture_connection_cannot_use_domain_fallback(): void
    {
        $validator = new LocalSmtpValidator();
        // Port zero cannot host an SMTP listener. The address literal also
        // keeps a guard-removal mutant confined to loopback.
        $validator->connect_port = 0;
        $results = $validator->validate(['user@[127.0.0.1]']);

        $attempts = array_values(array_filter(
            $validator->get_log(),
            static fn(string $line): bool => str_starts_with($line, 'Connecting to ')
        ));
        $this->assertSame(['Connecting to 127.0.0.1:0'], $attempts);
        $this->assertFalse($results['user@[127.0.0.1]']);
        $this->assertSame(
            'Local SMTP fixture refuses non-fixture host: [127.0.0.1]',
            $results['user@[127.0.0.1]_error_msg']
        );
    }
}
