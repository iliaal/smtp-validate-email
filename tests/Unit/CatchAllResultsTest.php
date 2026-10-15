<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\TestableValidator;

class CatchAllResultsTest extends TestCase
{
    public static function rejectedProbes(): array
    {
        $cases = [];
        foreach ([421, 450, 451, 452, 550, 252] as $code) {
            foreach ([false, true] as $policy) {
                foreach ([false, true] as $includeDomains) {
                    $cases[] = [$code, $policy, $includeDomains];
                }
            }
        }
        return $cases;
    }

    #[DataProvider('rejectedProbes')]
    public function test_probe_errors_do_not_appear_as_recipient_results(
        int $code,
        bool $policy,
        bool $includeDomains
    ): void {
        $validator = new TestableValidator();
        $validator->catchall_test = true;
        $validator->greylisted_considered_valid = $policy;
        $validator->queueResponse(
            "220 Ready\r\n", "250 Hello\r\n", "250 Sender OK\r\n", "250 NOOP\r\n",
            "$code Probe rejected\r\n", "250 NOOP\r\n",
            "250 Recipient OK\r\n", "550 No such user\r\n",
            "250 Reset\r\n", "221 Bye\r\n"
        );
        $results = $validator->validate(['alice@example.com', 'bob@example.com'], '', $includeDomains);
        $expected = [
            'alice@example.com' => true,
            'bob@example.com_error_msg' => 'such user',
            'bob@example.com' => false,
        ];
        if ($includeDomains) {
            $expected['domains'] = [
                'example.com' => ['users' => ['alice', 'bob'], 'mxs' => ['example.com' => 10]],
            ];
        }
        $this->assertSame($expected, $results);
        $this->assertSame($expected, $validator->get_results($includeDomains));
        $this->assertContains('Unexpected response to RCPT TO: rejected', $validator->get_log());
    }

    public function test_direct_probe_preserves_existing_recipient_errors(): void
    {
        $validator = new TestableValidator();
        $validator->setupSmtpSession();
        $validator->queueResponse("550 Real recipient rejected\r\n");
        $this->assertFalse($validator->exposedRcpt('alice@example.com'));
        $before = $validator->get_results(false);
        $validator->catchall_test = true;
        $validator->queueResponse("550 Probe rejected\r\n", "250 NOOP\r\n");
        $this->assertFalse($validator->accepts_any_recipient('example.com'));
        $this->assertSame($before, $validator->get_results(false));
        $validator->queueResponse("550 Another recipient rejected\r\n");
        $this->assertFalse($validator->exposedRcpt('bob@example.com'));
        $this->assertSame('recipient rejected', $validator->get_results(false)['bob@example.com_error_msg']);
    }
}
