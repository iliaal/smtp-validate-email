<?php

namespace SMTPValidateEmail\Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\Tests\E2E\LocalSmtpValidator;

/**
 * End-to-end tests against a local aiosmtpd test server.
 *
 * The server accepts RCPT TO for a fixed set of addresses and rejects
 * all others with 550. See tests/fixtures/smtp_test_server.py.
 *
 * Excluded from default phpunit runs. Run explicitly with:
 *   vendor/bin/phpunit --group e2e
 */
#[Group('e2e')]
class SmtpValidationTest extends TestCase
{
    private static int $serverPid = 0;
    private static int $port = 2525;
    private string $sender = 'test@localtest.test';

    public static function setUpBeforeClass(): void
    {
        $script = __DIR__ . '/../fixtures/smtp_test_server.py';
        $port = self::$port;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open(
            "python3 $script $port",
            $descriptors,
            $pipes
        );

        if (!is_resource($proc)) {
            self::fail('Failed to start SMTP test server');
        }

        self::$serverPid = proc_get_status($proc)['pid'];

        // Wait for the server to be ready (up to 3 seconds)
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($sock) {
                fclose($sock);
                return;
            }
            usleep(50000);
        }

        self::fail("SMTP test server did not start within 3 seconds on port $port");
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid > 0) {
            // Kill the process group to catch the child python process
            posix_kill(self::$serverPid, SIGTERM);
            // Also kill any python process on our port
            exec('pkill -f "smtp_test_server.py ' . self::$port . '" 2>/dev/null');
            usleep(100000);
        }
    }

    private function createValidator(): LocalSmtpValidator
    {
        $v = new LocalSmtpValidator();
        $v->connect_port = self::$port;
        $v->no_comm_is_valid = false;
        $v->no_conn_is_valid = false;
        return $v;
    }

    public function test_valid_email(): void
    {
        $v = $this->createValidator();
        $results = $v->validate(['valid@localtest.test'], $this->sender);

        $this->assertTrue($results['valid@localtest.test']);
    }

    public function test_invalid_email(): void
    {
        $v = $this->createValidator();
        $results = $v->validate(['nonexistent@localtest.test'], $this->sender);

        $this->assertFalse($results['nonexistent@localtest.test']);
    }

    public function test_multiple_valid_emails_same_domain(): void
    {
        $v = $this->createValidator();
        $results = $v->validate([
            'alice@localtest.test',
            'bob@localtest.test',
        ], $this->sender);

        $this->assertTrue($results['alice@localtest.test']);
        $this->assertTrue($results['bob@localtest.test']);
    }

    public function test_mixed_valid_and_invalid(): void
    {
        $v = $this->createValidator();
        $results = $v->validate([
            'valid@localtest.test',
            'doesnotexist@localtest.test',
            'alice@localtest.test',
        ], $this->sender);

        $this->assertTrue($results['valid@localtest.test']);
        $this->assertFalse($results['doesnotexist@localtest.test']);
        $this->assertTrue($results['alice@localtest.test']);
    }

    public function test_invalid_format_rejected_without_smtp(): void
    {
        $v = $this->createValidator();
        $results = $v->validate(['not-an-email'], $this->sender);

        $this->assertFalse($results['not-an-email']);
        $this->assertSame('Invalid email format', $results['not-an-email_error_msg']);
    }

    public function test_catchall_domain_detected(): void
    {
        $v = $this->createValidator();
        $v->catchall_test = true;
        $v->catchall_is_valid = false;

        $results = $v->validate(['anyone@catchall.test'], $this->sender);

        // Catch-all detected, and catchall_is_valid=false, so result is false
        $this->assertFalse($results['anyone@catchall.test']);
        $this->assertTrue($results['domains']['catchall.test']['catchall']);
    }

    public function test_catchall_domain_valid_when_configured(): void
    {
        $v = $this->createValidator();
        $v->catchall_test = true;
        $v->catchall_is_valid = true;

        $results = $v->validate(['anyone@catchall.test'], $this->sender);

        $this->assertTrue($results['anyone@catchall.test']);
    }

    public function test_non_catchall_domain(): void
    {
        $v = $this->createValidator();
        $v->catchall_test = true;

        $results = $v->validate(['valid@localtest.test'], $this->sender);

        $this->assertTrue($results['valid@localtest.test']);
        $this->assertArrayNotHasKey('catchall', $results['domains']['localtest.test']);
    }

    public function test_results_contain_domain_info(): void
    {
        $v = $this->createValidator();
        $results = $v->validate(['valid@localtest.test'], $this->sender);

        $this->assertArrayHasKey('domains', $results);
        $this->assertArrayHasKey('localtest.test', $results['domains']);
        $this->assertArrayHasKey('mxs', $results['domains']['localtest.test']);
        $this->assertArrayHasKey('users', $results['domains']['localtest.test']);
        $this->assertContains('valid', $results['domains']['localtest.test']['users']);
    }

    public function test_postmaster_accepted(): void
    {
        $v = $this->createValidator();
        $results = $v->validate(['postmaster@localtest.test'], $this->sender);

        $this->assertTrue($results['postmaster@localtest.test']);
    }

    public function test_duplicate_emails_checked_once(): void
    {
        $v = $this->createValidator();
        $v->debug = false;
        $results = $v->validate([
            'valid@localtest.test',
            'valid@localtest.test',
        ], $this->sender);

        // Dedup means only one result entry
        $this->assertTrue($results['valid@localtest.test']);

        // Log should show only one RCPT TO for this address
        $rcptCount = 0;
        foreach ($v->get_log() as $line) {
            if (str_contains($line, 'RCPT TO:<valid@localtest.test>')) {
                $rcptCount++;
            }
        }
        $this->assertSame(1, $rcptCount);
    }
}
