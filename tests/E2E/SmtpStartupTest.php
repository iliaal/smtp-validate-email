<?php

namespace SMTPValidateEmail\Tests\E2E;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[Group('e2e')]
class SmtpStartupTest extends TestCase
{
    public static function startupFailures(): array
    {
        return ['occupied port' => [true], 'child exits early' => [false]];
    }

    #[DataProvider('startupFailures')]
    public function test_failed_startup_is_reported_and_cleaned_up(bool $occupied): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertIsResource($listener);
        $port = substr(strrchr(stream_socket_get_name($listener, false), ':'), 1);
        $previousPythonHome = getenv('PYTHONHOME');
        if (!$occupied) {
            // A nonexistent Python home makes the child exit before importing
            // the fixture, regardless of installed third-party packages.
            putenv('PYTHONHOME=' . __FILE__ . '/missing');
        }
        $previous = getenv('E2E_SMTP_PORT');
        putenv('E2E_SMTP_PORT=' . $port);

        try {
            $failure = null;
            try {
                SmtpValidationTest::setUpBeforeClass();
            } catch (AssertionFailedError $exception) {
                $failure = $exception;
            }
            $this->assertNotNull($failure, 'The child must confirm successful fixture startup');
            $this->assertStringContainsString('SMTP test server', $failure->getMessage());
            $this->assertStringContainsString('on port ' . $port, $failure->getMessage());
            if (!$occupied) {
                $this->assertStringContainsString('exited before readiness', $failure->getMessage());
                $this->assertStringContainsString('Fatal Python error', $failure->getMessage());
            }
            $this->assertNull((new \ReflectionProperty(SmtpValidationTest::class, 'proc'))->getValue());
            $this->assertSame([], (new \ReflectionProperty(SmtpValidationTest::class, 'pipes'))->getValue());
            $this->assertIsResource($listener, 'Cleanup must not stop the unrelated listener');
        } finally {
            SmtpValidationTest::tearDownAfterClass();
            fclose($listener);
            putenv($previousPythonHome === false ? 'PYTHONHOME' : 'PYTHONHOME=' . $previousPythonHome);
            putenv($previous === false ? 'E2E_SMTP_PORT' : 'E2E_SMTP_PORT=' . $previous);
        }
    }
}
