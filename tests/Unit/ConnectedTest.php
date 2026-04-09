<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTPValidateEmail;

class ConnectedTest extends TestCase
{
    public function test_returns_true_with_open_stream(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $validator = new SMTPValidateEmail();

        $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $ref->setValue($validator, $pair[0]);

        $connected = (new \ReflectionMethod(SMTPValidateEmail::class, 'connected'))->invoke($validator);
        $this->assertTrue($connected);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function test_returns_false_with_no_socket(): void
    {
        $validator = new SMTPValidateEmail();
        $connected = (new \ReflectionMethod(SMTPValidateEmail::class, 'connected'))->invoke($validator);
        $this->assertFalse($connected);
    }

    public function test_returns_false_after_fclose(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $validator = new SMTPValidateEmail();

        $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $ref->setValue($validator, $pair[0]);

        fclose($pair[0]);
        $connected = (new \ReflectionMethod(SMTPValidateEmail::class, 'connected'))->invoke($validator);
        $this->assertFalse($connected);

        fclose($pair[1]);
    }

    public function test_returns_false_when_remote_end_closes(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $validator = new SMTPValidateEmail();

        $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $ref->setValue($validator, $pair[0]);

        // Close the remote end
        fclose($pair[1]);
        // Read to trigger EOF detection
        fread($pair[0], 1);

        $connected = (new \ReflectionMethod(SMTPValidateEmail::class, 'connected'))->invoke($validator);
        $this->assertFalse($connected);

        fclose($pair[0]);
    }
}
