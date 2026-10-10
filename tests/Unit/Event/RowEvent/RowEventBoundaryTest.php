<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event\RowEvent;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\Cache\ArrayCache;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Definitions\ConstFieldType;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\RowEvent\ColumnDTO;
use MySQLReplication\Event\RowEvent\ColumnDTOCollection;
use MySQLReplication\Event\RowEvent\RowEvent;
use MySQLReplication\Event\RowEvent\RowEventDesyncException;
use MySQLReplication\Event\RowEvent\TableMap;
use MySQLReplication\Event\RowEvent\TableMapCache;
use MySQLReplication\Repository\FieldDTO;
use MySQLReplication\Repository\RepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A rows event must decode to exactly its own boundary. Column metadata that does not describe the
 * written row image must stop the stream instead of emitting shifted values.
 */
class RowEventBoundaryTest extends TestCase
{
    private const TABLE_ID = '42';

    protected function tearDown(): void
    {
        (new ArrayCache())->clear();
        parent::tearDown();
    }

    public function testRowsThatEndExactlyAtTheBoundaryDecode(): void
    {
        $rowEvent = $this->rowEvent(ConstFieldType::LONG, $this->row(pack('V', 7)) . $this->row(pack('V', 8)), checkSum: true);

        self::assertSame([[
            'id' => 7,
        ], [
            'id' => 8,
        ]], $rowEvent->makeWriteRowsDTO()?->values);
    }

    public function testRowsThatRunIntoTheChecksumFailClosed(): void
    {
        // metadata claims BIGINT (8 bytes) but the row holds an INT (4 bytes): the decode eats the checksum
        $rowEvent = $this->rowEvent(ConstFieldType::LONGLONG, $this->row(pack('V', 7)), checkSum: true);

        $this->expectException(RowEventDesyncException::class);
        $this->expectExceptionMessage('decoded 4 bytes past its boundary');
        $rowEvent->makeWriteRowsDTO();
    }

    public function testRowsThatRunPastThePacketFailClosed(): void
    {
        $rowEvent = $this->rowEvent(ConstFieldType::LONGLONG, $this->row(pack('V', 7)), checkSum: false);

        $this->expectException(RowEventDesyncException::class);
        $rowEvent->makeDeleteRowsDTO();
    }

    public function testUpdateRowsThatRunPastThePacketFailClosed(): void
    {
        $rowEvent = $this->rowEvent(
            ConstFieldType::LONGLONG,
            $this->row(pack('V', 7)) . $this->row(pack('V', 8)),
            checkSum: false,
            type: ConstEventType::UPDATE_ROWS_EVENT_V2,
            bitmaps: 2
        );

        $this->expectException(RowEventDesyncException::class);
        $rowEvent->makeUpdateRowsDTO();
    }

    private function row(string $value): string
    {
        // null bitmap (nothing null) + value
        return chr(0) . $value;
    }

    private function rowEvent(
        int $columnType,
        string $rows,
        bool $checkSum,
        ConstEventType $type = ConstEventType::WRITE_ROWS_EVENT_V2,
        int $bitmaps = 1
    ): RowEvent {
        $tableMapCache = new TableMapCache(new ArrayCache());
        $columns = new ColumnDTOCollection();
        $columns->set(0, new ColumnDTO(new FieldDTO('id', null, null, '', 'int', 'PRI'), $columnType, 0, 0, 0, 0, 0, 0, 0, 0));
        $tableMapCache->set(self::TABLE_ID, new TableMap('db', 'tbl', self::TABLE_ID, 1, $columns));

        $body = substr(pack('P', (int)self::TABLE_ID), 0, 6) // table id
            . pack('v', 0)                                    // flags
            . pack('v', 2)                                    // v2 extra data length (no extra data)
            . chr(1)                                          // column count
            . str_repeat(chr(1), $bitmaps)                    // columns-present bitmap(s)
            . $rows
            . ($checkSum ? 'CRC!' : '');

        $current = new BinLogCurrent();
        $current->setBinFileName('binlog.000001');

        return new RowEvent(
            new BinaryDataReader($body, failOnReadPastEnd: true),
            new EventInfo(1, $type->value, 1, 19 + strlen($body), '500', 0, $checkSum, $current),
            $this->createStub(RepositoryInterface::class),
            new ArrayCache(),
            (new ConfigBuilder())->build(),
            $this->createStub(BinLogServerInfo::class),
            new NullLogger(),
            $tableMapCache
        );
    }
}
