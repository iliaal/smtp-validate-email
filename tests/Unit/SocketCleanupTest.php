<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTPValidateEmail;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_Timeout;

class SocketCleanupTest extends TestCase
{
    public static function cleanupPaths(): array
    {
        return [
            'peer closes before normal cleanup' => [false],
            'peer closes before a communication error' => [true],
        ];
    }

    #[DataProvider('cleanupPaths')]
    public function test_validate_releases_eof_socket_before_returning(bool $throw): void
    {
        $v = new class($throw) extends SMTPValidateEmail {
            public $client;
            private $peer;

            public function __construct(private bool $throw)
            {
                parent::__construct();
            }

            protected function mx_query($domain): array
            {
                return [[], []];
            }

            protected function connect($host): void
            {
                [$this->client, $this->peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
                (new \ReflectionProperty(SMTPValidateEmail::class, 'socket'))->setValue($this, $this->client);
            }

            protected function helo(): bool
            {
                return true;
            }

            protected function mail($from): bool
            {
                return true;
            }

            protected function noop(): void
            {
            }

            protected function rcpt($to): bool
            {
                // Model a peer closing during the final recipient probe.
                fclose($this->peer);
                fread($this->client, 1);
                if ($this->throw) {
                    throw new SMTP_Validate_Email_Exception_Timeout('Connection ended during RCPT');
                }
                return true;
            }
        };

        $results = $v->validate(['user@example.com']);

        $this->assertSame(!$throw, $results['user@example.com']);
        $this->assertFalse(is_resource($v->client), 'EOF sockets must be closed while the validator is still alive');
        $this->assertNull((new \ReflectionProperty(SMTPValidateEmail::class, 'socket'))->getValue($v));
    }
}
