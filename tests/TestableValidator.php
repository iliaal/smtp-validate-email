<?php

namespace SMTPValidateEmail\Tests;

use SMTPValidateEmail\SMTPValidateEmail;
use SMTPValidateEmail\SMTP_Validate_Email_Exception;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Connection;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_No_Response;
use SMTPValidateEmail\SMTP_Validate_Email_Exception_Timeout;

class TestableValidator extends SMTPValidateEmail
{
    /** @var string[] Queue of lines the "server" will send back */
    private array $recvQueue = [];

    /** @var string[] Record of commands sent */
    public array $sentCommands = [];

    /** @var bool Whether we're "connected" */
    private bool $isConnected = false;

    /** @var list<string>|null Hosts that should fail connect (for MX failover tests) */
    public ?array $failConnectHosts = null;

    /** @var list<array{0: list<string>, 1: list<int>}>|null Queue of mx_query results */
    public ?array $mxQueryResults = null;

    /** @var bool When true, next recv throws Timeout */
    public bool $throwTimeoutOnRecv = false;

    public function queueResponse(string ...$lines): void
    {
        foreach ($lines as $line) {
            $this->recvQueue[] = $line;
        }
    }

    public function setConnected(bool $connected): void
    {
        $this->isConnected = $connected;
    }

    public function clearSentCommands(): void
    {
        $this->sentCommands = [];
    }

    protected function connected(): bool
    {
        return $this->isConnected;
    }

    protected function connect($host): void
    {
        if ($this->failConnectHosts !== null && in_array($host, $this->failConnectHosts, true)) {
            throw new SMTP_Validate_Email_Exception_No_Connection(
                'Cannot open a connection to remote host (' . $host . ')'
            );
        }
        $this->isConnected = true;
        $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'connect_host');
        $ref->setValue($this, $host);
        $href = new \ReflectionProperty(SMTPValidateEmail::class, 'host');
        $href->setValue($this, $host . ':' . $this->connect_port);
    }

    protected function disconnect($quit = true): void
    {
        if ($quit) {
            $this->quit();
        }
        $this->isConnected = false;
        $this->resetStateViaReflection();
    }

    protected function send($cmd): int
    {
        if (!$this->connected()) {
            throw new SMTP_Validate_Email_Exception_No_Connection('No connection');
        }
        // Mirror production CR/LF guard so injection tests exercise the same path
        if (preg_match('/[\r\n\0]/', $cmd)) {
            throw new SMTP_Validate_Email_Exception('SMTP command contains invalid control characters');
        }
        $this->sentCommands[] = $cmd;
        return strlen($cmd);
    }

    protected function recv($timeout = null): string
    {
        if (!$this->connected()) {
            throw new SMTP_Validate_Email_Exception_No_Connection('No connection');
        }
        if ($this->throwTimeoutOnRecv) {
            $this->throwTimeoutOnRecv = false;
            throw new SMTP_Validate_Email_Exception_Timeout('Timed out in recv');
        }
        if (empty($this->recvQueue)) {
            throw new SMTP_Validate_Email_Exception_No_Response('No response in recv');
        }
        return array_shift($this->recvQueue);
    }

    protected function mx_query($domain): array
    {
        if ($this->mxQueryResults !== null && $this->mxQueryResults !== []) {
            return array_shift($this->mxQueryResults);
        }
        return [[$domain], [10]];
    }

    /**
     * Script a full HELO + MAIL FROM handshake so tests can jump straight
     * to testing RCPT TO, catch-all, etc.
     */
    public function setupSmtpSession(string $from = 'test@localhost'): void
    {
        $this->setConnected(true);
        // 220 greeting (consumed by helo -> expect)
        $this->queueResponse("220 mail.example.com ESMTP\r\n");
        // 250 EHLO response
        $this->queueResponse("250 mail.example.com\r\n");
        $this->exposedHelo();
        // 250 MAIL FROM response
        $this->queueResponse("250 OK\r\n");
        $this->exposedMail($from);
        $this->clearSentCommands();
    }

    public function exposedParseEmail(string $email): array
    {
        return $this->parse_email($email);
    }

    public function exposedHelo(): ?bool
    {
        return $this->helo();
    }

    public function exposedMail(string $from): bool
    {
        return $this->mail($from);
    }

    public function exposedRcpt(string $to): ?bool
    {
        return $this->rcpt($to);
    }

    public function exposedExpect($codes, $timeout = null, $emptyAllowed = false): string
    {
        return $this->expect($codes, $timeout, $emptyAllowed);
    }

    private function resetStateViaReflection(): void
    {
        $ref = new \ReflectionProperty(SMTPValidateEmail::class, 'state');
        $ref->setValue($this, [
            'helo' => false,
            'mail' => false,
            'rcpt' => false,
        ]);
        $tls = new \ReflectionProperty(SMTPValidateEmail::class, 'tls');
        $tls->setValue($this, false);
    }

    /**
     * Read a private/protected property from the parent class via reflection.
     */
    public function getProperty(string $name): mixed
    {
        $ref = new \ReflectionProperty(SMTPValidateEmail::class, $name);
        return $ref->getValue($this);
    }
}
