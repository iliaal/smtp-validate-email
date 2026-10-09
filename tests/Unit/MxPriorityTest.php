<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class MxPriorityTest extends TestCase
{
    public static function mxRecords(): array
    {
        return [
            'domain is primary MX' => [
                ['backup.example.com', 'example.com'], [20, 0],
                ['example.com' => 0, 'backup.example.com' => 20],
            ],
            'domain is backup MX' => [
                ['example.com', 'primary.example.com'], [20, 10],
                ['primary.example.com' => 10, 'example.com' => 20],
            ],
            'domain fallback is appended' => [
                ['backup.example.com', 'primary.example.com'], [20, 10],
                ['primary.example.com' => 10, 'backup.example.com' => 20, 'example.com' => 21],
            ],
            'no MX records' => [[], [], ['example.com' => 0]],
        ];
    }

    #[DataProvider('mxRecords')]
    public function test_reported_priorities_match_dns_and_connection_order(
        array $hosts,
        array $weights,
        array $expected
    ): void {
        $validator = new class extends TestableValidator {
            public array $attemptedHosts = [];

            protected function connect($host): void
            {
                $this->attemptedHosts[] = $host;
                parent::connect($host);
            }
        };
        $validator->mxQueryResults = [[$hosts, $weights]];
        // Refuse every mock connection to exercise the complete failover order.
        $validator->failConnectHosts = array_keys($expected);
        $results = $validator->validate(['user@example.com']);

        $this->assertFalse($results['user@example.com']);
        $this->assertSame($expected, $results['domains']['example.com']['mxs']);
        $this->assertSame(array_keys($expected), $validator->attemptedHosts);
    }
}
