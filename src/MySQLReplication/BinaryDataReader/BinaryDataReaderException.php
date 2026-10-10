<?php

declare(strict_types=1);

namespace MySQLReplication\BinaryDataReader;

use MySQLReplication\Exception\MySQLReplicationException;

class BinaryDataReaderException extends MySQLReplicationException
{
    public const READ_PAST_END_CODE = 419;

    public static function readPastEnd(int $requested, int $available): self
    {
        return new self(
            'Tried to read ' . $requested . ' bytes with only ' . max(0, $available) . ' left in the packet',
            self::READ_PAST_END_CODE
        );
    }
}
