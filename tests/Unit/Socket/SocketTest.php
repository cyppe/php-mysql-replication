<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Socket;

use MySQLReplication\Socket\Socket;
use MySQLReplication\Socket\SocketException;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class SocketTest extends TestCase
{
    /** @var array{0: \Socket, 1: \Socket} */
    private array $pair;

    private Socket $socket;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = [];
        self::assertTrue(socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $pair));
        $this->pair = $pair;

        $this->socket = new Socket();
        (new ReflectionProperty(Socket::class, 'socket'))->setValue($this->socket, $this->pair[0]);
    }

    protected function tearDown(): void
    {
        unset($this->socket);
        socket_close($this->pair[1]);
        parent::tearDown();
    }

    public function testReadsExactlyTheRequestedBytes(): void
    {
        socket_write($this->pair[1], 'abcdef');

        self::assertSame('abc', $this->socket->readFromSocket(3));
        self::assertSame('def', $this->socket->readFromSocket(3));
    }

    public function testZeroLengthReadReturnsAnEmptyStringWithoutBlocking(): void
    {
        self::assertSame('', $this->socket->readFromSocket(0));
    }

    public function testPeerCloseIsADisconnect(): void
    {
        socket_close($this->pair[1]);
        $this->pair[1] = socket_create(AF_UNIX, SOCK_STREAM, 0);

        $this->expectException(SocketException::class);
        $this->socket->readFromSocket(4);
    }
}
