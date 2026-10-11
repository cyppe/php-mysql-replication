<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Socket;

use ErrorException;
use InvalidArgumentException;
use MySQLReplication\Socket\Socket;
use MySQLReplication\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Exercises the socket against real loopback peers. A guard alarm turns a read that would block forever
 * (the behaviour without a timeout) into a failure instead of a hung test run.
 */
class SocketTest extends TestCase
{
    private const GUARD_SECONDS = 5;

    /** @var resource|null */
    private $server;

    /** @var resource|null */
    private $peer;

    /** @var resource|null */
    private $process;

    protected function setUp(): void
    {
        if (!function_exists('pcntl_alarm')) {
            self::markTestSkipped('pcntl is required for the guard alarm.');
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            throw new RuntimeException('Guard alarm: the read blocked past ' . self::GUARD_SECONDS . ' s.');
        }, false);
        pcntl_alarm(self::GUARD_SECONDS);
    }

    protected function tearDown(): void
    {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);

        if (is_resource($this->peer)) {
            fclose($this->peer);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
    }

    public function testShouldFailASilentConnectionWithinTheReadTimeout(): void
    {
        // The peer completes the TCP handshake (the kernel backlog does) but never sends or closes:
        // the shape of a connection dropped by a middlebox without FIN or RST.
        $port = $this->listen();
        $socket = new Socket(0.5);
        $socket->connectToStream('127.0.0.1', $port);

        $started = hrtime(true);
        $exception = $this->readExpectingFailure($socket, 4);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame(SocketException::SOCKET_READ_TIMEOUT_CODE, $exception->getCode());
        self::assertGreaterThanOrEqual(0.45, $elapsed);
        self::assertLessThan(2.0, $elapsed);
    }

    public function testShouldFailAPartialPacketWithinTheReadTimeout(): void
    {
        $port = $this->listen();
        $socket = new Socket(0.5);
        $socket->connectToStream('127.0.0.1', $port);
        $this->peer = $this->accept();
        fwrite($this->peer, 'ab');

        $started = hrtime(true);
        $exception = $this->readExpectingFailure($socket, 4);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame(SocketException::SOCKET_PARTIAL_READ_CODE, $exception->getCode());
        self::assertSame('Read 2 of 4 bytes before the read stopped.', $exception->getMessage());
        self::assertLessThan(2.0, $elapsed);
    }

    public function testShouldReportACleanDisconnectAtOnce(): void
    {
        $port = $this->listen();
        $socket = new Socket(5.0);
        $socket->connectToStream('127.0.0.1', $port);
        fclose($this->accept());

        $started = hrtime(true);
        $exception = $this->readExpectingFailure($socket, 4);
        $elapsed = (hrtime(true) - $started) / 1e9;

        self::assertSame(SocketException::SOCKET_DISCONNECTED_CODE, $exception->getCode());
        self::assertLessThan(1.0, $elapsed);
    }

    public function testShouldReportAResetConnectionAsASocketExceptionEvenWhenWarningsThrow(): void
    {
        // Mirrors a Laravel application: E_ALL reporting, and HandleExceptions::handleError turning a reported
        // warning into an ErrorException, which the reconnect path (catching SocketException) would never see.
        $previousReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $level, string $message): bool {
            if ((error_reporting() & $level) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $level);
        });

        try {
            $port = $this->listen();
            $socket = new Socket(5.0);
            $socket->connectToStream('127.0.0.1', $port);
            // SO_LINGER 0 makes the close send RST instead of FIN.
            $stream = $this->accept();
            $peer = socket_import_stream($stream);
            self::assertNotFalse($peer);
            socket_set_option($peer, SOL_SOCKET, SO_LINGER, ['l_onoff' => 1, 'l_linger' => 0]);
            fclose($stream);

            $exception = $this->readExpectingFailure($socket, 4);
            self::assertSame(SOCKET_ECONNRESET, $exception->getCode());

            unset($socket);
        } finally {
            restore_error_handler();
            error_reporting($previousReporting);
        }
    }

    public function testShouldApplyTheTimeoutToEachReadSoAQuietButLiveStreamKeepsReading(): void
    {
        // A heartbeat-like peer: 4 bytes every 0.3 s for 6 rounds (about 1.8 s in total), well past
        // the 0.5 s timeout for the stream as a whole but inside it for every single read.
        $port = $this->spawnPeer('for ($i = 0; $i < 6; $i++) { usleep(300000); fwrite($c, "beat"); } usleep(200000);');
        $socket = new Socket(0.5);
        $socket->connectToStream('127.0.0.1', $port);

        for ($i = 0; $i < 6; ++$i) {
            self::assertSame('beat', $socket->readFromSocket(4));
        }
    }

    public function testShouldReadAPacketThatArrivesInPieces(): void
    {
        $port = $this->spawnPeer('fwrite($c, "ab"); usleep(200000); fwrite($c, "cd"); usleep(200000);');
        $socket = new Socket(1.0);
        $socket->connectToStream('127.0.0.1', $port);

        self::assertSame('abcd', $socket->readFromSocket(4));
    }

    public function testShouldRejectANegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Socket(-1.0);
    }

    private function readExpectingFailure(Socket $socket, int $length): SocketException
    {
        try {
            $socket->readFromSocket($length);
        } catch (SocketException $exception) {
            return $exception;
        }

        self::fail('Expected a SocketException.');
    }

    private function listen(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($server, $error);
        $this->server = $server;

        return $this->portOf($server);
    }

    /**
     * @return resource
     */
    private function accept()
    {
        self::assertNotNull($this->server);
        $peer = stream_socket_accept($this->server, 1.0);
        self::assertNotFalse($peer);

        return $peer;
    }

    /**
     * Runs a one-connection peer in a child PHP process, so it can write while this process blocks in a read.
     * The child prints its port, accepts one connection as $c, runs $body, then exits.
     */
    private function spawnPeer(string $body): int
    {
        $script = '$s = stream_socket_server("tcp://127.0.0.1:0"); echo explode(":", stream_socket_get_name($s, false))[1], "\n";'
            . ' $c = stream_socket_accept($s, 5); ' . $body;
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $this->process = $process;

        $port = (int)fgets($pipes[1]);
        self::assertGreaterThan(0, $port);

        return $port;
    }

    /**
     * @param resource $server
     */
    private function portOf($server): int
    {
        $name = stream_socket_get_name($server, false);
        self::assertNotFalse($name);

        return (int)substr($name, strrpos($name, ':') + 1);
    }
}
