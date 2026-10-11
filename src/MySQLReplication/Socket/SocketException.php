<?php

declare(strict_types=1);

namespace MySQLReplication\Socket;

use MySQLReplication\Exception\MySQLReplicationException;

class SocketException extends MySQLReplicationException
{
    public const SOCKET_DISCONNECTED_MESSAGE = 'Disconnected by remote side.';
    public const SOCKET_DISCONNECTED_CODE = 100;
    public const SOCKET_UNABLE_TO_WRITE_MESSAGE = 'Unable to write to socket: ';
    public const SOCKET_UNABLE_TO_WRITE_CODE = 101;
    public const SOCKET_UNABLE_TO_CREATE_MESSAGE = 'Unable to create socket: ';
    public const SOCKET_UNABLE_TO_CREATE_CODE = 102;
    public const SOCKET_READ_TIMEOUT_MESSAGE = 'No data from the replication source within the read timeout of %s s.';
    public const SOCKET_READ_TIMEOUT_CODE = 103;
    public const SOCKET_PARTIAL_READ_MESSAGE = 'Read %d of %d bytes before the read stopped.';
    public const SOCKET_PARTIAL_READ_CODE = 104;
    public const SOCKET_UNABLE_TO_CONNECT_TIMEOUT_MESSAGE = 'Unable to connect within the timeout of %s s.';
    public const SOCKET_UNABLE_TO_CONNECT_TIMEOUT_CODE = 105;
}
