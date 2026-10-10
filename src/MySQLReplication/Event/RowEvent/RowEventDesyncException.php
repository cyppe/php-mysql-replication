<?php

declare(strict_types=1);

namespace MySQLReplication\Event\RowEvent;

use MySQLReplication\Event\EventInfo;
use MySQLReplication\Exception\MySQLReplicationException;
use Throwable;

/**
 * Thrown when decoding a rows event does not end exactly at the event's boundary: the column
 * metadata used to decode it (usually information_schema after a schema change) does not
 * describe the row image that was written, so every decoded value is suspect.
 */
class RowEventDesyncException extends MySQLReplicationException
{
    public const DESYNC_CODE = 418;

    /**
     * @param int $unreadBytes bytes left before the boundary; negative when the decode ran past it
     */
    public static function make(TableMap $tableMap, EventInfo $eventInfo, int $unreadBytes, ?Throwable $previous = null): self
    {
        return new self(
            sprintf(
                'Rows event (type %d) for %s.%s (table_id %s, ending at %s:%s) decoded %d bytes %s its boundary; '
                . 'the column metadata does not match the written row image (schema changed while the consumer lagged?). '
                . 'Refusing to emit misdecoded rows.',
                $eventInfo->type,
                $tableMap->database,
                $tableMap->table,
                $tableMap->tableId,
                $eventInfo->binLogCurrent->getBinFileName(),
                $eventInfo->pos,
                abs($unreadBytes),
                $unreadBytes > 0 ? 'short of' : 'past'
            ),
            self::DESYNC_CODE,
            $previous
        );
    }
}
