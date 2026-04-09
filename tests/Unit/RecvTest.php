<?php

namespace SMTPValidateEmail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SMTPValidateEmail\SMTPValidateEmail;

class RecvTest extends TestCase
{
    public function test_reads_long_response_line(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $validator = new SMTPValidateEmail();

        $socketRef = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $socketRef->setValue($validator, $pair[0]);

        // Write a line longer than 1024 bytes (the old limit)
        $longLine = '250-' . str_repeat('X', 2000) . "\r\n";
        fwrite($pair[1], $longLine);

        $recvMethod = new \ReflectionMethod(SMTPValidateEmail::class, 'recv');
        $result = $recvMethod->invoke($validator, 5);

        // After fix, should read the full line (up to 4095 bytes)
        $this->assertGreaterThan(1024, strlen($result));
        $this->assertStringStartsWith('250-', $result);

        fclose($pair[0]);
        fclose($pair[1]);
    }

    public function test_reads_normal_response(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $validator = new SMTPValidateEmail();

        $socketRef = new \ReflectionProperty(SMTPValidateEmail::class, 'socket');
        $socketRef->setValue($validator, $pair[0]);

        fwrite($pair[1], "250 OK\r\n");

        $recvMethod = new \ReflectionMethod(SMTPValidateEmail::class, 'recv');
        $result = $recvMethod->invoke($validator, 5);

        $this->assertSame("250 OK\r\n", $result);

        fclose($pair[0]);
        fclose($pair[1]);
    }
}
