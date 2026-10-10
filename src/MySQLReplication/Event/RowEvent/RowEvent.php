<?php

declare(strict_types=1);

namespace MySQLReplication\Event\RowEvent;

use DateTime;
use Exception;
use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\BinaryDataReader\BinaryDataReaderException;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\Config\Config;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Definitions\ConstFieldType;
use MySQLReplication\Event\DTO\DeleteRowsDTO;
use MySQLReplication\Event\DTO\TableMapDTO;
use MySQLReplication\Event\DTO\UpdateRowsDTO;
use MySQLReplication\Event\DTO\WriteRowsDTO;
use MySQLReplication\Event\EventCommon;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Exception\MySQLReplicationException;
use MySQLReplication\JsonBinaryDecoder\JsonBinaryDecoderService;
use MySQLReplication\Repository\RepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use WeakMap;

class RowEvent extends EventCommon
{
    /**
     * Read MySQL's new decimal format introduced in MySQL 5
     * https://dev.mysql.com/doc/refman/5.6/en/precision-math-decimal-characteristics.html
     */
    private const DECIMAL_COMPRESSED_BYTES = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];
    private static array $bitCountInByte = [
        0,
        1,
        1,
        2,
        1,
        2,
        2,
        3,
        1,
        2,
        2,
        3,
        2,
        3,
        3,
        4,
        1,
        2,
        2,
        3,
        2,
        3,
        3,
        4,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        1,
        2,
        2,
        3,
        2,
        3,
        3,
        4,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        1,
        2,
        2,
        3,
        2,
        3,
        3,
        4,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        4,
        5,
        5,
        6,
        5,
        6,
        6,
        7,
        1,
        2,
        2,
        3,
        2,
        3,
        3,
        4,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        4,
        5,
        5,
        6,
        5,
        6,
        6,
        7,
        2,
        3,
        3,
        4,
        3,
        4,
        4,
        5,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        4,
        5,
        5,
        6,
        5,
        6,
        6,
        7,
        3,
        4,
        4,
        5,
        4,
        5,
        5,
        6,
        4,
        5,
        5,
        6,
        5,
        6,
        6,
        7,
        4,
        5,
        5,
        6,
        5,
        6,
        6,
        7,
        5,
        6,
        6,
        7,
        6,
        7,
        7,
        8,
    ];

    private ?TableMap $currentTableMap;

    /**
     * @var WeakMap<TableMap, array{0: list<array{0: int, 1: string, 2: int, 3: bool, 4: ColumnDTO}>, 1: string}>|null
     */
    private static ?WeakMap $columnPlans = null;

    /**
     * @var array<string, int> columns-present bitmap => number of present columns, per event
     */
    private array $presentCounts = [];

    private readonly TableMapCache $tableMapCache;

    private readonly TableMapBuilder $tableMapBuilder;

    public function __construct(
        protected BinaryDataReader $binaryDataReader,
        protected EventInfo $eventInfo,
        RepositoryInterface $repository,
        CacheInterface $cache,
        private readonly Config $config,
        protected BinLogServerInfo $binLogServerInfo,
        private readonly LoggerInterface $logger,
        ?TableMapCache $tableMapCache = null,
        ?TableMapBuilder $tableMapBuilder = null
    ) {
        $this->tableMapCache = $tableMapCache ?? new TableMapCache($cache);
        $this->tableMapBuilder = $tableMapBuilder ?? new TableMapBuilder($repository, $config, $logger);

        parent::__construct($eventInfo, $binaryDataReader, $binLogServerInfo);
    }

    /**
     * This describes the structure of a table.
     * It's send before a change append on a table.
     * An end user of the lib should have no usage of this
     */
    public function makeTableMapDTO(): ?TableMapDTO
    {
        $data = [];
        $data['table_id'] = $this->binaryDataReader->readTableId();
        $this->binaryDataReader->advance(2);
        $data['schema_length'] = $this->binaryDataReader->readUInt8();
        $data['schema_name'] = $this->binaryDataReader->read($data['schema_length']);

        if ($this->config->checkDataBasesOnly($data['schema_name']) || $this->config->checkDataBasesIgnore($data['schema_name'])) {
            return null;
        }

        $this->binaryDataReader->advance(1);
        $data['table_length'] = $this->binaryDataReader->readUInt8();
        $data['table_name'] = $this->binaryDataReader->read($data['table_length']);

        if ($this->config->checkTablesOnly($data['table_name']) || $this->config->checkTablesIgnore($data['table_name'])) {
            return null;
        }

        $this->binaryDataReader->advance(1);
        $data['columns_amount'] = (int)$this->binaryDataReader->readCodedBinary();
        $data['column_types'] = $this->binaryDataReader->read($data['columns_amount']);

        $cachedTableMap = $this->tableMapCache->has($data['table_id']) ? $this->tableMapCache->get($data['table_id']) : null;
        if ($cachedTableMap instanceof TableMap && !$this->config->requireTableMapMetadata) {
            return new TableMapDTO($this->eventInfo, $cachedTableMap);
        }

        $this->binaryDataReader->readCodedBinary();

        $tableMap = $this->tableMapBuilder->build(
            $this->binaryDataReader,
            $this->eventInfo,
            $data['schema_name'],
            $data['table_name'],
            $data['table_id'],
            $data['columns_amount'],
            $data['column_types']
        );

        // Require mode builds every TABLE_MAP_EVENT, cached or not, so the builder checks its own metadata: a table first
        // mapped under FULL cannot pass a later MINIMAL map. A map that passes and equals the cached one leaves the cache
        // as it was, so no other table's map is evicted before its rows arrive and the cached map keeps its decoding plan.
        // A map that differs (another source sharing the static cache under the same table id) replaces the cached one.
        if ($cachedTableMap instanceof TableMap && $cachedTableMap == $tableMap) {
            return new TableMapDTO($this->eventInfo, $cachedTableMap);
        }

        $this->tableMapCache->set($data['table_id'], $tableMap);

        return new TableMapDTO($this->eventInfo, $tableMap);
    }

    public function makeWriteRowsDTO(): ?WriteRowsDTO
    {
        $this->currentTableMap = $this->findTableMap();
        if ($this->currentTableMap === null) {
            return null;
        }

        $values = $this->getValues();

        return new WriteRowsDTO($this->eventInfo, $this->currentTableMap, count($values), $values);
    }

    public function makeDeleteRowsDTO(): ?DeleteRowsDTO
    {
        $this->currentTableMap = $this->findTableMap();
        if ($this->currentTableMap === null) {
            return null;
        }

        $values = $this->getValues();

        return new DeleteRowsDTO($this->eventInfo, $this->currentTableMap, count($values), $values);
    }

    public function makeUpdateRowsDTO(): ?UpdateRowsDTO
    {
        $tableMap = $this->currentTableMap = $this->findTableMap();
        if ($tableMap === null) {
            return null;
        }

        // same guard as getValues(): without columns we cannot decode the row images
        if ($tableMap->columnDTOCollection->isEmpty()) {
            return new UpdateRowsDTO($this->eventInfo, $tableMap, 0, []);
        }

        $columnsBinarySize = $this->getColumnsBinarySize($tableMap->columnsAmount);
        $beforeBinaryData = $this->binaryDataReader->read($columnsBinarySize);
        $afterBinaryData = $this->binaryDataReader->read($columnsBinarySize);

        $trailerLength = $this->getTrailerLength();
        $values = [];
        try {
            while ($this->binaryDataReader->getBinaryDataLength() > $trailerLength) {
                $values[] = [
                    'before' => $this->getColumnData($beforeBinaryData),
                    'after' => $this->getColumnData($afterBinaryData),
                ];
            }
        } catch (BinaryDataReaderException $exception) {
            throw RowEventDesyncException::make($tableMap, $this->eventInfo, -1, $exception);
        }
        $this->assertRowsEndAtEventBoundary($trailerLength);

        return new UpdateRowsDTO($this->eventInfo, $tableMap, count($values), $values);
    }

    protected function findTableMap(): ?TableMap
    {
        $tableId = $this->binaryDataReader->readTableId();
        $this->binaryDataReader->advance(2);

        if (in_array(
            $this->eventInfo->type,
            [
                ConstEventType::DELETE_ROWS_EVENT_V2->value,
                ConstEventType::WRITE_ROWS_EVENT_V2->value,
                ConstEventType::UPDATE_ROWS_EVENT_V2->value,
            ],
            true
        )) {
            // v2 extra data: a 2-byte length that counts itself, then extra-row-info such as the
            // partition id MySQL 8.0.16+ writes for partitioned tables (and NDB info). Skip it whole.
            $extraDataLength = $this->binaryDataReader->readUInt16();
            if ($extraDataLength > 2) {
                $this->binaryDataReader->advance($extraDataLength - 2);
            }
        }

        $this->binaryDataReader->readCodedBinary();

        if ($this->tableMapCache->has($tableId)) {
            return $this->tableMapCache->get($tableId);
        }

        $this->logger->debug('No table map found for table ID: ' . $tableId);

        return null;
    }

    protected function getValues(): array
    {
        // if we don't get columns from information schema we don't know how to assign them
        $tableMap = $this->currentTableMap;
        if ($tableMap === null || $tableMap->columnDTOCollection->isEmpty()) {
            return [];
        }

        $binaryData = $this->binaryDataReader->read($this->getColumnsBinarySize($tableMap->columnsAmount));

        $trailerLength = $this->getTrailerLength();
        $values = [];
        try {
            while ($this->binaryDataReader->getBinaryDataLength() > $trailerLength) {
                $values[] = $this->getColumnData($binaryData);
            }
        } catch (BinaryDataReaderException $exception) {
            throw RowEventDesyncException::make($tableMap, $this->eventInfo, -1, $exception);
        }
        $this->assertRowsEndAtEventBoundary($trailerLength);

        return $values;
    }

    /**
     * Rows run to the end of the packet minus the trailing checksum. Measuring the bytes left in
     * the packet (instead of bytes read against the header's event size) stays correct when a
     * semi-sync prefix precedes the event header.
     */
    protected function getTrailerLength(): int
    {
        return $this->eventInfo->checkSum ? 4 : 0;
    }

    /**
     * A correct decode consumes the row images exactly. Ending anywhere else means the column
     * metadata does not describe the written rows, and every value (keys included) is suspect.
     */
    protected function assertRowsEndAtEventBoundary(int $trailerLength): void
    {
        $unreadBytes = $this->binaryDataReader->getBinaryDataLength() - $trailerLength;
        if ($unreadBytes !== 0 && $this->currentTableMap !== null) {
            throw RowEventDesyncException::make($this->currentTableMap, $this->eventInfo, $unreadBytes);
        }
    }

    protected function getColumnsBinarySize(int $columnsAmount): int
    {
        return (int)(($columnsAmount + 7) / 8);
    }

    protected function getIgnore(ColumnDTO $columnDTO): null
    {
        $this->binaryDataReader->advance($columnDTO->lengthSize);

        return null;
    }

    protected function getYear(): ?int
    {
        $year = $this->binaryDataReader->readUInt8();

        return $year === 0 ? null : 1900 + $year;
    }

    protected function getColumnData(string $colsBitmap): array
    {
        if ($this->currentTableMap === null) {
            throw new RuntimeException('Current table map is missing!');
        }

        [$columns, $fullBitmap] = $this->getColumnPlan($this->currentTableMap);
        // a full row image (every column present) skips the per-column presence test
        $allPresent = $colsBitmap === $fullBitmap;
        $presentCount = $allPresent ? count($columns) : ($this->presentCounts[$colsBitmap] ??= $this->bitCount($colsBitmap));

        $values = [];

        // null bitmap length = (bits set in 'columns-present-bitmap'+7)/8
        // see http://dev.mysql.com/doc/internals/en/rows-event.html
        $nullBitmap = $this->binaryDataReader->read($this->getColumnsBinarySize($presentCount));
        $nullBitmapIndex = 0;

        foreach ($columns as [$i, $name, $type, $unsigned, $columnDTO]) {
            if (!$allPresent && (ord($colsBitmap[$i >> 3]) & (1 << ($i & 7))) === 0) {
                $values[$name] = null;
                continue;
            }

            if ((ord($nullBitmap[$nullBitmapIndex >> 3]) & (1 << ($nullBitmapIndex & 7))) !== 0) {
                $values[$name] = null;
            } else {
                $values[$name] = match ($type) {
                    ConstFieldType::IGNORE => $this->getIgnore($columnDTO),
                    ConstFieldType::TINY => $unsigned ? $this->binaryDataReader->readUInt8() : $this->binaryDataReader->readInt8(),
                    ConstFieldType::SHORT => $unsigned ? $this->binaryDataReader->readUInt16() : $this->binaryDataReader->readInt16(),
                    ConstFieldType::LONG => $unsigned ? $this->binaryDataReader->readUInt32() : $this->binaryDataReader->readInt32(),
                    ConstFieldType::LONGLONG => $unsigned ? $this->binaryDataReader->readUInt64() : $this->binaryDataReader->readInt64(),
                    ConstFieldType::INT24 => $unsigned ? $this->binaryDataReader->readUInt24() : $this->binaryDataReader->readInt24(),
                    // http://dev.mysql.com/doc/refman/5.7/en/floating-point-types.html FLOAT(7,4)
                    ConstFieldType::FLOAT => round($this->binaryDataReader->readFloat(), 4),
                    ConstFieldType::DOUBLE => $this->binaryDataReader->readDouble(),
                    ConstFieldType::VARCHAR, ConstFieldType::STRING => $columnDTO->maxLength > 255 ? $this->getString(2) : $this->getString(
                        1
                    ),
                    ConstFieldType::NEWDECIMAL => $this->getDecimal($columnDTO),
                    ConstFieldType::BLOB, ConstFieldType::GEOMETRY => $this->getString($columnDTO->lengthSize),
                    ConstFieldType::DATETIME => $this->getDatetime(),
                    ConstFieldType::DATETIME2 => $this->getDatetime2($columnDTO),
                    ConstFieldType::TIMESTAMP => date('Y-m-d H:i:s', $this->binaryDataReader->readUInt32()),
                    ConstFieldType::TIME => $this->getTime(),
                    ConstFieldType::TIME2 => $this->getTime2($columnDTO),
                    ConstFieldType::TIMESTAMP2 => $this->getTimestamp2($columnDTO),
                    ConstFieldType::DATE => $this->getDate(),
                    // https://dev.mysql.com/doc/refman/5.7/en/year.html
                    ConstFieldType::YEAR => $this->getYear(),
                    ConstFieldType::ENUM => $this->getEnum($columnDTO),
                    ConstFieldType::SET => $this->getSet($columnDTO),
                    ConstFieldType::BIT => $this->getBit($columnDTO),
                    ConstFieldType::JSON => JsonBinaryDecoderService::makeJsonBinaryDecoder(
                        $this->getString($columnDTO->lengthSize)
                    )->parseToString(),
                    default => throw new MySQLReplicationException('Unknown row type: ' . $type),
                };
            }

            ++$nullBitmapIndex;
        }

        return $values;
    }

    /**
     * Per-table decode plan, built once per TableMap instead of once per column per row: the
     * column offset, name, type, unsigned flag and DTO as a plain list, plus the columns-present
     * bitmap of a full row image.
     *
     * @return array{0: list<array{0: int, 1: string, 2: int, 3: bool, 4: ColumnDTO}>, 1: string}
     */
    protected function getColumnPlan(TableMap $tableMap): array
    {
        self::$columnPlans ??= new WeakMap();

        if (isset(self::$columnPlans[$tableMap])) {
            return self::$columnPlans[$tableMap];
        }

        $columns = [];
        foreach ($tableMap->columnDTOCollection as $i => $columnDTO) {
            $columns[] = [$i, $columnDTO->getName(), $columnDTO->type, $columnDTO->isUnsigned(), $columnDTO];
        }

        $columnsAmount = $tableMap->columnsAmount;
        $fullBitmap = str_repeat("\xFF", intdiv($columnsAmount, 8));
        if ($columnsAmount % 8 !== 0) {
            $fullBitmap .= chr((1 << ($columnsAmount % 8)) - 1);
        }

        return self::$columnPlans[$tableMap] = [$columns, $fullBitmap];
    }

    protected function bitCount(string $bitmap): int
    {
        $n = 0;
        $bitmapLength = strlen($bitmap);
        for ($i = 0; $i < $bitmapLength; ++$i) {
            $n += self::$bitCountInByte[ord($bitmap[$i])];
        }

        return $n;
    }

    protected function bitGet(string $bitmap, int $position): int
    {
        return $this->getBitFromBitmap($bitmap, $position) & (1 << ($position & 7));
    }

    protected function getBitFromBitmap(string $bitmap, int $position): int
    {
        return ord($bitmap[(int)($position / 8)]);
    }

    protected function isNull(string $nullBitmap, int $position): bool
    {
        return ($this->getBitFromBitmap($nullBitmap, $position) & (1 << ($position % 8))) > 0;
    }

    protected function getString(int $size): string
    {
        return $this->binaryDataReader->readLengthString($size);
    }

    protected function getDecimal(ColumnDTO $columnDTO): string
    {
        $digitsPerInteger = 9;
        $compressedBytes = self::DECIMAL_COMPRESSED_BYTES;
        $integral = $columnDTO->precision - $columnDTO->decimals;
        $unCompIntegral = (int)($integral / $digitsPerInteger);
        $unCompFractional = (int)($columnDTO->decimals / $digitsPerInteger);
        $compIntegral = $integral - ($unCompIntegral * $digitsPerInteger);
        $compFractional = $columnDTO->decimals - ($unCompFractional * $digitsPerInteger);

        $value = $this->binaryDataReader->readUInt8();
        if (0 !== ($value & 0x80)) {
            $mask = 0;
            $res = '';
        } else {
            $mask = -1;
            $res = '-';
        }
        $this->binaryDataReader->unread(pack('C', $value ^ 0x80));

        $size = $compressedBytes[$compIntegral];
        if ($size > 0) {
            $value = $this->binaryDataReader->readIntBeBySize($size) ^ $mask;
            $res .= $value;
        }

        for ($i = 0; $i < $unCompIntegral; ++$i) {
            $value = $this->binaryDataReader->readInt32Be() ^ $mask;
            $res .= sprintf('%09d', $value);
        }

        $res .= '.';

        for ($i = 0; $i < $unCompFractional; ++$i) {
            $value = $this->binaryDataReader->readInt32Be() ^ $mask;
            $res .= sprintf('%09d', $value);
        }

        $size = $compressedBytes[$compFractional];
        if ($size > 0) {
            $value = $this->binaryDataReader->readIntBeBySize($size) ^ $mask;
            $res .= sprintf('%0' . $compFractional . 'd', $value);
        }

        if (is_numeric($res)) {
            return bcmul($res, '1', $columnDTO->decimals);
        }

        return '0';
    }

    protected function getDatetime(): ?string
    {
        $value = $this->binaryDataReader->readUInt64();
        // nasty mysql 0000-00-00 dates
        if ($value === '0') {
            return null;
        }

        return $this->dateFormatter($value);
    }

    /**
     * Date Time
     * 1 bit  sign           (1= non-negative, 0= negative)
     * 17 bits year*13+month  (year 0-9999, month 0-12)
     * 5 bits day            (0-31)
     * 5 bits hour           (0-23)
     * 6 bits minute         (0-59)
     * 6 bits second         (0-59)
     * ---------------------------
     * 40 bits = 5 bytes
     *
     * @link https://dev.mysql.com/doc/internals/en/date-and-time-data-type-representation.html
     */
    protected function getDatetime2(ColumnDTO $columnDTO): ?string
    {
        $data = $this->binaryDataReader->readIntBeBySize(5);

        $yearMonth = $this->binaryDataReader->getBinarySlice($data, 1, 17, 40);

        $year = (int)($yearMonth / 13);
        $month = $yearMonth % 13;
        $day = $this->binaryDataReader->getBinarySlice($data, 18, 5, 40);
        $hour = $this->binaryDataReader->getBinarySlice($data, 23, 5, 40);
        $minute = $this->binaryDataReader->getBinarySlice($data, 28, 6, 40);
        $second = $this->binaryDataReader->getBinarySlice($data, 34, 6, 40);
        $fsp = $this->getFSP($columnDTO);

        $formattedDate = $this->dateFormatter($year . '-' . $month . '-' . $day . ' ' . $hour . ':' . $minute . ':' . $second);
        if ($formattedDate !== null) {
            if ($fsp > 0) {
                return vsprintf('%s.%06u', [$formattedDate, $fsp]);
            }
            return $formattedDate;
        }

        return null;
    }

    protected function dateFormatter(string $date): ?string
    {
        try {
            $dateTime = new DateTime($date);
        } catch (Exception $exception) {
            $dateTime = DateTime::createFromFormat('YmdHis', $date);
            if ($dateTime === false) {
                $this->logger->warning('Failed to parse date "' . $date);
                return null;
            }
        }

        $formattedDate = $dateTime->format('Y-m-d H:i:s');
        if (DateTime::getLastErrors() !== false) {
            $this->logger->warning('Failed to parse date "' . $date);
            return null;
        }

        return $formattedDate;
    }

    /**
     * @link https://dev.mysql.com/doc/internals/en/date-and-time-data-type-representation.html
     */
    protected function getFSP(ColumnDTO $columnDTO): string
    {
        $read = 0;
        $time = '';
        $fsp = $columnDTO->fsp;
        if ($fsp === 1 || $fsp === 2) {
            $read = 1;
        } elseif ($fsp === 3 || $fsp === 4) {
            $read = 2;
        } elseif ($fsp === 5 || $fsp === 6) {
            $read = 3;
        }
        if ($read > 0) {
            $microsecond = $this->binaryDataReader->readIntBeBySize($read);
            if ($fsp % 2 !== 0) {
                $microsecond = (int)($microsecond / 10);
            }
            $time = $microsecond * (10 ** (6 - $fsp));
        }

        return (string)$time;
    }

    protected function getTime(): string
    {
        $data = $this->binaryDataReader->readUInt24();
        if ($data === 0) {
            return '00:00:00';
        }

        return sprintf('%s%02d:%02d:%02d', $data < 0 ? '-' : '', $data / 10000, ($data % 10000) / 100, $data % 100);
    }

    /**
     * TIME2 (MySQL 5.6.4+), my_time_packed_from_binary() in mysys/my_time.cc: a big-endian
     * integer part offset by 0x800000 (1 bit sign, 1 unused, 10 bits hour, 6 bits minute,
     * 6 bits second), then 0-3 bytes of fraction whose sign follows the integer part.
     * Hours run to 838 and values can be negative, so this must not go through DateTime.
     */
    protected function getTime2(ColumnDTO $columnDTO): string
    {
        $intPart = $this->readUInt24Be() - 0x800000;
        $fsp = $columnDTO->fsp;

        if ($fsp === 1 || $fsp === 2) {
            $frac = $this->binaryDataReader->readUInt8();
            if ($intPart < 0 && $frac !== 0) {
                ++$intPart;
                $frac -= 0x100;
            }
            $packed = $intPart * 0x1000000 + $frac * 10000;
        } elseif ($fsp === 3 || $fsp === 4) {
            $frac = $this->readUInt16Be();
            if ($intPart < 0 && $frac !== 0) {
                ++$intPart;
                $frac -= 0x10000;
            }
            $packed = $intPart * 0x1000000 + $frac * 100;
        } elseif ($fsp === 5 || $fsp === 6) {
            $packed = ($intPart + 0x800000) * 0x1000000 + $this->readUInt24Be() - 0x800000000000;
        } else {
            $packed = $intPart * 0x1000000;
        }

        $sign = $packed < 0 ? '-' : '';
        $packed = abs($packed);
        $hms = $packed >> 24;
        $time = sprintf('%s%02d:%02d:%02d', $sign, ($hms >> 12) & 0x3FF, ($hms >> 6) & 0x3F, $hms & 0x3F);

        return $fsp > 0 ? $time . '.' . sprintf('%06d', $packed % 0x1000000) : $time;
    }

    protected function getTimestamp2(ColumnDTO $columnDTO): string
    {
        $datetime = date('Y-m-d H:i:s', $this->binaryDataReader->readInt32Be());
        $fsp = $this->getFSP($columnDTO);
        if ($fsp !== '') {
            // getFSP() returns microseconds without leading zeros (.012 -> "12000")
            $datetime .= '.' . str_pad($fsp, 6, '0', STR_PAD_LEFT);
        }

        return $datetime;
    }

    private function readUInt24Be(): int
    {
        $bytes = $this->binaryDataReader->read(3);

        return (ord($bytes[0]) << 16) | (ord($bytes[1]) << 8) | ord($bytes[2]);
    }

    private function readUInt16Be(): int
    {
        $bytes = $this->binaryDataReader->read(2);

        return (ord($bytes[0]) << 8) | ord($bytes[1]);
    }

    protected function getDate(): ?string
    {
        $time = $this->binaryDataReader->readUInt24();
        if ($time === 0) {
            return null;
        }

        $year = ($time & ((1 << 15) - 1) << 9) >> 9;
        $month = ($time & ((1 << 4) - 1) << 5) >> 5;
        $day = ($time & ((1 << 5) - 1));
        if ($year === 0 || $month === 0 || $day === 0) {
            return null;
        }

        return (new DateTime())->setDate($year, $month, $day)
            ->format('Y-m-d');
    }

    protected function getEnum(ColumnDTO $columnDTO): string
    {
        // ENUM storage is always 1 or 2 bytes (max 65535 members), never the 8-byte case
        $value = (int)$this->binaryDataReader->readUIntBySize($columnDTO->size) - 1;

        // check if given value exists in enums, if there not existing enum mysql returns empty string.
        if (array_key_exists($value, $columnDTO->getEnumValues())) {
            return $columnDTO->getEnumValues()[$value];
        }

        return '';
    }

    protected function getSet(ColumnDTO $columnDTO): array
    {
        // we read set columns as a bitmap telling us which options are enabled
        // note: a SET with all 64 members enabled overflows a native PHP int (see
        // BinaryDataReader::readUIntBySize()); this cast keeps existing behavior for that
        // already-unsupported edge case rather than changing it here
        $bitMask = (int)$this->binaryDataReader->readUIntBySize($columnDTO->size);
        $sets = [];
        foreach ($columnDTO->getSetValues() as $k => $item) {
            if (($bitMask & (2 ** $k)) !== 0) {
                $sets[] = $item;
            }
        }

        return $sets;
    }

    protected function getBit(ColumnDTO $columnDTO): string
    {
        $res = '';
        for ($byte = 0; $byte < $columnDTO->bytes; ++$byte) {
            $data = $this->binaryDataReader->readUInt8();
            if ($byte === 0) {
                if ($columnDTO->bytes === 1) {
                    $end = $columnDTO->bits;
                } else {
                    $end = $columnDTO->bits % 8;
                    if ($end === 0) {
                        $end = 8;
                    }
                }
            } else {
                $end = 8;
            }

            $res .= sprintf('%0' . $end . 'b', $data & ((1 << $end) - 1));
        }

        return $res;
    }
}
