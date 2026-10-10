<?php

declare(strict_types=1);

namespace MySQLReplication\Event\RowEvent;

use MySQLReplication\Exception\MySQLReplicationException;

/**
 * Thrown when require_table_map_metadata is on and a TABLE_MAP_EVENT does not carry complete
 * column metadata (binlog_row_metadata was not FULL when the event was written).
 */
class TableMapMetadataException extends MySQLReplicationException
{
    public const INCOMPLETE_METADATA_CODE = 417;

    public static function incomplete(
        string $schemaName,
        string $tableName,
        string $tableId,
        int $columnsAmount,
        int $columnNamesAmount,
        string $binLogFileName,
        string $binLogPosition
    ): self {
        return new self(
            sprintf(
                'TABLE_MAP_EVENT for %s.%s (table_id %s, ending at %s:%s) carries %d of %d column names or lacks ENUM/SET literals; '
                . 'binlog_row_metadata=FULL is required because requireTableMapMetadata is on. '
                . 'Refusing to fall back to information_schema, which can mislabel rows written before a schema change.',
                $schemaName,
                $tableName,
                $tableId,
                $binLogFileName,
                $binLogPosition,
                $columnNamesAmount,
                $columnsAmount
            ),
            self::INCOMPLETE_METADATA_CODE
        );
    }
}
