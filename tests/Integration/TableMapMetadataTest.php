<?php

/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace MySQLReplication\Tests\Integration;

use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Event\DTO\FormatDescriptionEventDTO;
use MySQLReplication\Event\DTO\QueryDTO;
use MySQLReplication\Event\DTO\RotateDTO;
use MySQLReplication\Event\DTO\TableMapDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\DTO\XidDTO;
use MySQLReplication\Event\RowEvent\ColumnDTO;
use MySQLReplication\Event\RowEvent\ColumnDTOCollection;
use MySQLReplication\Event\RowEvent\TableMap;
use MySQLReplication\Event\RowEvent\TableMapMetadataException;
use MySQLReplication\Repository\FieldDTO;

class TableMapMetadataTest extends BaseCase
{
    private const CREATE_QUERY = 'CREATE TABLE test (
        id INT UNSIGNED NOT NULL PRIMARY KEY,
        status ENUM("open", "closed", "pending"),
        tags SET("a", "b", "c"),
        name VARCHAR(20)
    )';

    private const INSERT_QUERY = 'INSERT INTO test VALUES (42, "closed", "a,c", "hello")';

    public function testShouldReadColumnNamesUnsignedEnumSetAndPrimaryKeyFromTableMapEvent(): void
    {
        $event = $this->createAndInsertValue(self::CREATE_QUERY, self::INSERT_QUERY);
        self::assertInstanceOf(WriteRowsDTO::class, $event);

        $this->assertColumnMetadataAndValues($event);
    }

    public function testShouldFallBackToInformationSchemaWhenMetadataDisabled(): void
    {
        $this->disconnect();
        $this->configBuilder->withUseTableMapMetadata(false);
        $this->connect();

        // connect() doesn't replay setUp()'s initial-event dance; reproduce it here so
        // createAndInsertValue() sees the same starting position it expects.
        if ($this->mySQLReplicationFactory?->getServerInfo()->versionRevision >= 8 && $this->mySQLReplicationFactory?->getServerInfo()->isGeneric()) {
            self::assertInstanceOf(RotateDTO::class, $this->getEvent());
        }
        self::assertInstanceOf(FormatDescriptionEventDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());

        $event = $this->createAndInsertValue(self::CREATE_QUERY, self::INSERT_QUERY);
        self::assertInstanceOf(WriteRowsDTO::class, $event);

        $this->assertColumnMetadataAndValues($event);
    }

    public function testYearColumnDoesNotShiftTheSignednessOfLaterNumericColumns(): void
    {
        $event = $this->createAndInsertValue(
            'CREATE TABLE test (y YEAR, a INT UNSIGNED, b INT, c BIGINT UNSIGNED, d TINYINT)',
            'INSERT INTO test VALUES (2020, 4294967295, -1, 18446744073709551615, -5)'
        );
        self::assertInstanceOf(WriteRowsDTO::class, $event);

        self::assertSame(2020, $event->values[0]['y']);
        self::assertSame(4294967295, $event->values[0]['a']);
        self::assertSame(-1, $event->values[0]['b']);
        self::assertSame('18446744073709551615', $event->values[0]['c']);
        self::assertSame(-5, $event->values[0]['d']);
    }

    public function testRequiredMetadataDecodesFullMetadataEvents(): void
    {
        $this->reconnectRequiringMetadata();

        $event = $this->createAndInsertValue(self::CREATE_QUERY, self::INSERT_QUERY);
        self::assertInstanceOf(WriteRowsDTO::class, $event);

        $this->assertColumnMetadataAndValues($event);
    }

    public function testRequiredMetadataFailsClosedOnMinimalMetadataEvents(): void
    {
        $this->reconnectRequiringMetadata();

        $this->connection->executeStatement(self::CREATE_QUERY);
        $this->connection->executeStatement('SET GLOBAL binlog_row_metadata = MINIMAL');
        try {
            $this->connection->executeStatement(self::INSERT_QUERY);
        } finally {
            $this->connection->executeStatement('SET GLOBAL binlog_row_metadata = FULL');
        }

        self::assertInstanceOf(QueryDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());

        $this->expectException(TableMapMetadataException::class);
        $this->expectExceptionMessage('TABLE_MAP_EVENT for mysqlreplication_test.test');
        $this->getEvent();
    }

    public function testRequiredMetadataChecksATableMapItHasCachedAlready(): void
    {
        $this->reconnectRequiringMetadata();

        self::assertInstanceOf(WriteRowsDTO::class, $this->createAndInsertValue(self::CREATE_QUERY, self::INSERT_QUERY));

        $this->connection->executeStatement('SET GLOBAL binlog_row_metadata = MINIMAL');
        try {
            $this->connection->executeStatement('INSERT INTO test VALUES (43, "open", "b", "again")');
        } finally {
            $this->connection->executeStatement('SET GLOBAL binlog_row_metadata = FULL');
        }

        self::assertInstanceOf(XidDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());

        $this->expectException(TableMapMetadataException::class);
        $this->expectExceptionMessage('TABLE_MAP_EVENT for mysqlreplication_test.test');
        $this->getEvent();
    }

    public function testRequiredMetadataKeepsTheCachedMapOfATableThatPassesAgain(): void
    {
        $this->reconnectRequiringMetadata();

        $first = $this->createAndInsertValue(self::CREATE_QUERY, self::INSERT_QUERY);
        self::assertInstanceOf(WriteRowsDTO::class, $first);

        $this->connection->executeStatement('INSERT INTO test VALUES (43, "open", "b", "again")');

        self::assertInstanceOf(XidDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());
        $tableMapEvent = $this->getEvent();
        self::assertInstanceOf(TableMapDTO::class, $tableMapEvent);

        // The second map was checked and passed; the cache still holds the first map, so nothing was re-set or evicted.
        self::assertSame($first->tableMap, $tableMapEvent->tableMap);
    }

    public function testRequiredMetadataReplacesACachedMapThatDiffersFromTheEvent(): void
    {
        $this->reconnectRequiringMetadata();

        $first = $this->createAndInsertValue(self::CREATE_QUERY, self::INSERT_QUERY);
        self::assertInstanceOf(WriteRowsDTO::class, $first);

        // Another source sharing the static cache mapped the same table id to a table whose columns have other names.
        $columns = new ColumnDTOCollection();
        foreach ($first->tableMap->columnDTOCollection as $column) {
            $field = $column->fieldDTO;
            $columns->add(new ColumnDTO(
                new FieldDTO('other_' . $field->columnName, $field->collationName, $field->characterSetName, $field->columnComment, $field->columnType, $field->columnKey),
                $column->type,
                $column->maxLength,
                $column->size,
                $column->fsp,
                $column->lengthSize,
                $column->precision,
                $column->decimals,
                $column->bits,
                $column->bytes
            ));
        }
        $foreign = new TableMap('other_db', 'other_table', $first->tableMap->tableId, $first->tableMap->columnsAmount, $columns);
        (new ArrayCache())->set($first->tableMap->tableId, $foreign);

        $this->connection->executeStatement('INSERT INTO test VALUES (43, "open", "b", "again")');

        self::assertInstanceOf(XidDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());
        $tableMapEvent = $this->getEvent();
        self::assertInstanceOf(TableMapDTO::class, $tableMapEvent);
        self::assertSame('test', $tableMapEvent->tableMap->table);
        self::assertNotSame($foreign, (new ArrayCache())->get($first->tableMap->tableId));

        $rows = $this->getEvent();
        self::assertInstanceOf(WriteRowsDTO::class, $rows);
        self::assertSame(['id', 'status', 'tags', 'name'], array_keys($rows->values[0]));
        self::assertSame('again', $rows->values[0]['name']);
    }

    private function reconnectRequiringMetadata(): void
    {
        if ($this->mySQLReplicationFactory?->getServerInfo()->isMariaDb()
            || $this->connection->fetchOne('SELECT @@GLOBAL.binlog_row_metadata') !== 'FULL') {
            self::markTestSkipped('Needs a MySQL source with binlog_row_metadata=FULL');
        }

        $this->disconnect();
        $this->configBuilder->withRequireTableMapMetadata();
        $this->connect();

        if ($this->mySQLReplicationFactory?->getServerInfo()->versionRevision >= 8 && $this->mySQLReplicationFactory?->getServerInfo()->isGeneric()) {
            self::assertInstanceOf(RotateDTO::class, $this->getEvent());
        }
        self::assertInstanceOf(FormatDescriptionEventDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());
        self::assertInstanceOf(QueryDTO::class, $this->getEvent());
    }

    private function assertColumnMetadataAndValues(WriteRowsDTO $event): void
    {
        $columns = $event->tableMap->columnDTOCollection;

        self::assertSame('id', $columns->offsetGet(0)->getName());
        self::assertTrue($columns->offsetGet(0)->isUnsigned());
        self::assertTrue($columns->offsetGet(0)->isPrimary());

        self::assertSame('status', $columns->offsetGet(1)->getName());
        self::assertSame(['open', 'closed', 'pending'], $columns->offsetGet(1)->getEnumValues());

        self::assertSame('tags', $columns->offsetGet(2)->getName());
        self::assertSame(['a', 'b', 'c'], $columns->offsetGet(2)->getSetValues());

        self::assertSame('name', $columns->offsetGet(3)->getName());
        self::assertFalse($columns->offsetGet(3)->isPrimary());

        self::assertSame(42, $event->values[0]['id']);
        self::assertSame('closed', $event->values[0]['status']);
        self::assertSame(['a', 'c'], $event->values[0]['tags']);
        self::assertSame('hello', $event->values[0]['name']);
    }
}
