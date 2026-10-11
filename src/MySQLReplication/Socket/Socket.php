<?php

declare(strict_types=1);

namespace MySQLReplication\Socket;

use InvalidArgumentException;
use Socket as NativeSocket;

class Socket implements SocketInterface
{
    private NativeSocket $socket;

    /**
     * @param float $timeoutSeconds Deadline for connect, for each read and for each write; 0 waits forever.
     *                              A replication stream is only quiet for longer than the source's heartbeat
     *                              period when the connection is dead, so a timeout of several heartbeat
     *                              periods turns a silently dropped connection into a SocketException.
     */
    public function __construct(
        private readonly float $timeoutSeconds = 0.0
    ) {
        if ($timeoutSeconds < 0.0) {
            throw new InvalidArgumentException('The socket timeout must be 0 (disabled) or positive.');
        }
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
            throw new SocketException(SocketException::SOCKET_UNABLE_TO_CREATE_MESSAGE . $this->getSocketErrorMessage(), SocketException::SOCKET_UNABLE_TO_CREATE_CODE);
        }
        $this->socket = $socket;
        socket_set_block($this->socket);
        socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);

        if ($this->timeoutSeconds > 0.0) {
            // SO_RCVTIMEO bounds each blocking recv, including the whole of a MSG_WAITALL read on Linux.
            // SO_SNDTIMEO bounds each write and, on Linux, the blocking connect.
            $timeval = $this->timeval();
            socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, $timeval);
            socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, $timeval);
        }

        if (!@socket_connect($this->socket, $host, $port)) {
            $code = $this->getSocketErrorCode();
            if ($this->timeoutSeconds > 0.0 && in_array($code, [SOCKET_EINPROGRESS, SOCKET_EAGAIN, SOCKET_ETIMEDOUT], true)) {
                throw new SocketException(sprintf(SocketException::SOCKET_UNABLE_TO_CONNECT_TIMEOUT_MESSAGE, $this->timeoutSeconds), SocketException::SOCKET_UNABLE_TO_CONNECT_TIMEOUT_CODE);
            }

            throw new SocketException($this->getSocketErrorMessage(), $code);
        }
    }

    public function readFromSocket(int $length): string
    {
        // Failures are reported as a SocketException below, which callers treat as retryable. Without the
        // silence operator PHP also raises a warning, which a framework error handler (Laravel's) turns into
        // an ErrorException that bypasses the reconnect path.
        $received = @socket_recv($this->socket, $buf, $length, MSG_WAITALL);
        if ($length === $received) {
            return $buf;
        }

        // http://php.net/manual/en/function.socket-recv.php#47182
        if ($received === 0) {
            throw new SocketException(SocketException::SOCKET_DISCONNECTED_MESSAGE, SocketException::SOCKET_DISCONNECTED_CODE);
        }

        // A short read means the read timeout expired or a signal interrupted the read part-way.
        // The packet is incomplete either way, so the stream cannot continue from here.
        if (is_int($received) && $received > 0) {
            throw new SocketException(sprintf(SocketException::SOCKET_PARTIAL_READ_MESSAGE, $received, $length), SocketException::SOCKET_PARTIAL_READ_CODE);
        }

        $code = $this->getSocketErrorCode();
        if ($this->timeoutSeconds > 0.0 && in_array($code, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true)) {
            throw new SocketException(sprintf(SocketException::SOCKET_READ_TIMEOUT_MESSAGE, $this->timeoutSeconds), SocketException::SOCKET_READ_TIMEOUT_CODE);
        }

        throw new SocketException($this->getSocketErrorMessage(), $code);
    }

    public function writeToSocket(string $data): void
    {
        if (@socket_write($this->socket, $data, strlen($data)) === false) {
            throw new SocketException(SocketException::SOCKET_UNABLE_TO_WRITE_MESSAGE . $this->getSocketErrorMessage(), SocketException::SOCKET_UNABLE_TO_WRITE_CODE);
        }
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
        return socket_last_error();
    }
}
