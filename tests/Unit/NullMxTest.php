<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class NullMxTest extends TestCase
{
    private function validator(array $records): TestableValidator
    {
        $validator = new class extends TestableValidator {
            public array $attemptedHosts = [];

            protected function connect($host): void
            {
                $this->attemptedHosts[] = $host;
                parent::connect($host);
            }
        };
        $validator->mxQueryResults = $records;
        return $validator;
    }

    public static function nullRecords(): array
    {
        return [
            'presentation root, strict policies' => ['.', false],
            'presentation root, permissive policies' => ['.', true],
            'getmxrr empty root, strict policies' => ['', false],
            'getmxrr empty root, permissive policies' => ['', true],
        ];
    }

    #[DataProvider('nullRecords')]
    public function test_null_mx_rejects_all_recipients_without_connecting(string $host, bool $permissive): void
    {
        $validator = $this->validator([[[$host], [0]]]);
        $validator->no_conn_is_valid = $permissive;
        $validator->no_comm_is_valid = $permissive;
        $validator->catchall_test = true;
        $results = $validator->validate(['alice@example.com', 'bob@example.com']);

        $this->assertSame([], $validator->attemptedHosts);
        $this->assertSame([], $validator->sentCommands);
        foreach (['alice', 'bob'] as $user) {
            $this->assertFalse($results[$user . '@example.com']);
            $this->assertSame('Domain does not accept mail (null MX)', $results[$user . '@example.com_error_msg']);
        }
        $this->assertSame(['users' => ['alice', 'bob'], 'mxs' => [$host => 0]], $results['domains']['example.com']);
    }

    public function test_null_mx_does_not_abort_later_domains(): void
    {
        $validator = $this->validator([[['.'], [0]], [['other.example'], [10]]]);
        $validator->queueResponse("220 Ready\r\n", "250 Hello\r\n", "250 Sender OK\r\n", "250 Recipient OK\r\n", "250 Reset\r\n", "221 Bye\r\n");
        $results = $validator->validate(['alice@example.com', 'bob@other.example'], '', false);

        $this->assertFalse($results['alice@example.com']);
        $this->assertTrue($results['bob@other.example']);
        $this->assertSame(['other.example'], $validator->attemptedHosts);
        $this->assertArrayNotHasKey('domains', $results);
    }

    public static function otherRecords(): array
    {
        return [
            'no MX still uses fallback' => [[], [], ['example.com']],
            'ordinary zero-priority MX' => [['mx.example.com'], [0], ['mx.example.com', 'example.com']],
            'two MX records are not a null MX declaration' => [['.', 'mx.example.com'], [0, 10], ['.', 'mx.example.com', 'example.com']],
            'negative preference is not a null MX declaration' => [['.'], [-1], ['.', 'example.com']],
            'positive preference is not a null MX declaration' => [['.'], [1], ['.', 'example.com']],
        ];
    }

    #[DataProvider('otherRecords')]
    public function test_other_records_keep_existing_connection_policy(array $hosts, array $weights, array $attempts): void
    {
        $validator = $this->validator([[$hosts, $weights]]);
        $validator->failConnectHosts = $attempts;
        $validator->no_conn_is_valid = true;
        $results = $validator->validate(['alice@example.com']);

        $this->assertTrue($results['alice@example.com']);
        $this->assertSame($attempts, $validator->attemptedHosts);
    }
}
