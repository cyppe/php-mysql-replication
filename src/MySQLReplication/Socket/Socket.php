<?php

declare(strict_types=1);

namespace MySQLReplication\Socket;

use InvalidArgumentException;
use Socket as NativeSocket;

class Socket implements SocketInterface
{
    /** The largest timeout a timeval's seconds field holds on every platform. */
    private const MAX_TIMEOUT_SECONDS = 2147483647.0;

    private NativeSocket $socket;

    /**
     * Declared with a default rather than promoted, so a subclass with its own constructor keeps the
     * previous unlimited behaviour.
     */
    private float $timeoutSeconds = 0.0;

    /**
     * @param float $timeoutSeconds How long connect, a read or a write may wait without any progress; 0 waits
     *                              forever. A replication source sends an event or a heartbeat at least once
     *                              per heartbeat period, so a timeout of several periods turns a silently
     *                              dropped connection into a SocketException. A read fails after one timeout
     *                              without any new byte; a packet already partly received gets at most one
     *                              more timeout before it fails.
     */
    public function __construct(float $timeoutSeconds = 0.0)
    {
        if (!is_finite($timeoutSeconds) || $timeoutSeconds < 0.0 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('The socket timeout must be 0 (disabled) or a finite positive number of seconds.');
        }

        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function __destruct()
    {
        if (!isset($this->socket)) {
            return;
        }

        // A dead connection cannot shut down cleanly; that is not an error worth a warning here.
        @socket_shutdown($this->socket);
        socket_close($this->socket);
    }

    public function connectToStream(string $host, int $port): void
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($socket === false) {
            throw new SocketException(SocketException::SOCKET_UNABLE_TO_CREATE_MESSAGE . socket_strerror(socket_last_error()), SocketException::SOCKET_UNABLE_TO_CREATE_CODE);
        }
        $this->socket = $socket;
        socket_set_block($this->socket);
        socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);

        if ($this->timeoutSeconds > 0.0) {
            // SO_RCVTIMEO bounds each blocking recv; SO_SNDTIMEO bounds each write and, on Linux, the blocking connect.
            $timeval = $this->timeval();
            if (!socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeval)
                || !socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $timeval)) {
                throw new SocketException(SocketException::SOCKET_UNABLE_TO_SET_TIMEOUT_MESSAGE . $this->getSocketErrorMessage(), SocketException::SOCKET_UNABLE_TO_SET_TIMEOUT_CODE);
            }
        }

        // Failures are reported as a SocketException, which callers treat as retryable. Without the silence
        // operator PHP also raises a warning, which a framework error handler (Laravel's) turns into an
        // ErrorException that bypasses the reconnect path. The same applies to every socket call below.
        if (!@socket_connect($this->socket, $host, $port)) {
            $code = $this->getSocketErrorCode();
            if ($this->isTimeout($code) || $code === SOCKET_EINPROGRESS) {
                throw new SocketException(sprintf(SocketException::SOCKET_CONNECT_TIMEOUT_MESSAGE, $this->timeoutSeconds, $code), SocketException::SOCKET_CONNECT_TIMEOUT_CODE);
            }

            throw new SocketException($this->getSocketErrorMessage(), $code);
        }
    }

    public function readFromSocket(int $length): string
    {
        $received = @socket_recv($this->socket, $buf, $length, MSG_WAITALL);
        if ($length === $received) {
            return $buf;
        }

        return $this->readRemainder($length, $received, is_int($received) && $received > 0 ? $buf : '');
    }

    public function writeToSocket(string $data): void
    {
        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $sent = @socket_write($this->socket, $written === 0 ? $data : substr($data, $written), $length - $written);

            if ($sent === false) {
                $code = $this->getSocketErrorCode();
                if ($code === SOCKET_EINTR) {
                    continue;
                }
                if ($this->isTimeout($code)) {
                    throw new SocketException(sprintf(SocketException::SOCKET_WRITE_TIMEOUT_MESSAGE, $written, $length, $this->timeoutSeconds), SocketException::SOCKET_WRITE_TIMEOUT_CODE);
                }

                throw new SocketException(SocketException::SOCKET_UNABLE_TO_WRITE_MESSAGE . $this->getSocketErrorMessage(), SocketException::SOCKET_UNABLE_TO_WRITE_CODE);
            }

            if ($sent === 0) {
                throw new SocketException(SocketException::SOCKET_UNABLE_TO_WRITE_MESSAGE . 'no bytes written', SocketException::SOCKET_UNABLE_TO_WRITE_CODE);
            }

            $written += $sent;
        }
    }

    /**
     * Finishes a read whose first MSG_WAITALL call came back short. A short count comes from the read timeout
     * after partial data, a signal, or the peer closing; each further call waits at most one timeout for the
     * next bytes, so a packet that keeps arriving, however slowly, is read whole, and a connection that stops
     * delivering fails within one timeout.
     */
    private function readRemainder(int $length, int|false $received, string $data): string
    {
        while (true) {
            if ($received === 0) {
                // http://php.net/manual/en/function.socket-recv.php#47182
                throw new SocketException(SocketException::SOCKET_DISCONNECTED_MESSAGE, SocketException::SOCKET_DISCONNECTED_CODE);
            }

            if ($received === false) {
                $code = $this->getSocketErrorCode();
                if ($code !== SOCKET_EINTR) {
                    if ($this->isTimeout($code)) {
                        throw new SocketException(sprintf(SocketException::SOCKET_READ_TIMEOUT_MESSAGE, $this->timeoutSeconds, strlen($data), $length), SocketException::SOCKET_READ_TIMEOUT_CODE);
                    }

                    throw new SocketException($this->getSocketErrorMessage(), $code);
                }
            }

            if (strlen($data) === $length) {
                return $data;
            }

            // Without MSG_WAITALL each call returns as soon as any bytes arrive, so the timeout measures
            // time without progress rather than time to complete the packet.
            $received = @socket_recv($this->socket, $chunk, $length - strlen($data), 0);
            if (is_int($received) && $received > 0) {
                $data .= $chunk;
            }
        }
    }

    private function isTimeout(int $code): bool
    {
        return $this->timeoutSeconds > 0.0 && in_array($code, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true);
    }

    /**
     * @return array{sec: int, usec: int}
     */
    private function timeval(): array
    {
        $seconds = (int)floor($this->timeoutSeconds);
        $microseconds = (int)round(($this->timeoutSeconds - $seconds) * 1_000_000);

        if ($microseconds >= 1_000_000) {
            ++$seconds;
            $microseconds -= 1_000_000;
        }

        // An all-zero timeval would mean "wait forever".
        if ($seconds === 0 && $microseconds === 0) {
            $microseconds = 1;
        }

        return [
            'sec' => $seconds,
            'usec' => $microseconds,
        ];
    }

    private function getSocketErrorMessage(): string
    {
        return socket_strerror($this->getSocketErrorCode());
    }

    private function getSocketErrorCode(): int
    {
        return isset($this->socket) ? socket_last_error($this->socket) : socket_last_error();
    }
}
