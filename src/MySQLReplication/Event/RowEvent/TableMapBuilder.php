<?php

declare(strict_types=1);

namespace MySQLReplication\Event\RowEvent;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\Config\Config;
use MySQLReplication\Definitions\ConstFieldType;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Repository\FieldDTO;
use MySQLReplication\Repository\FieldDTOCollection;
use MySQLReplication\Repository\RepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the TableMap for a TABLE_MAP_EVENT: reads the per-column structural metadata,
 * then resolves column names/unsigned/ENUM-SET/primary-key info either from the event's own
 * metadata fields (binlog_row_metadata=FULL) or, when that's absent, from information_schema.
 */
readonly class TableMapBuilder
{
    public function __construct(
        private RepositoryInterface $repository,
        private Config $config,
        private LoggerInterface $logger
    ) {
    }

    public function build(
        BinaryDataReader $binaryDataReader,
        EventInfo $eventInfo,
        string $schemaName,
        string $tableName,
        string $tableId,
        int $columnsAmount,
        string $columnTypes
    ): TableMap {
        $rawColumnDTOs = $this->readStructuralColumnData($binaryDataReader, $columnTypes);

        // null-bitmap: must be consumed to reach the metadata fields that follow it
        $binaryDataReader->read($this->getColumnsBinarySize($columnsAmount));

        $tableMapMetadata = $this->parseMetadata($binaryDataReader, $eventInfo, $rawColumnDTOs);

        // binlog_row_metadata=MINIMAL still writes a metadata block (e.g. signedness) but
        // never a complete column-name list; only FULL emits COLUMN_NAME for every
        // column, so an empty or partial list cannot safely replace information_schema.
        if ($tableMapMetadata !== null && $this->isComplete($tableMapMetadata, $rawColumnDTOs)) {
            $this->logger->debug('Resolving column metadata for ' . $schemaName . '.' . $tableName . ' from TABLE_MAP_EVENT metadata');
            $fieldDTOCollection = $this->makeFieldDTOCollectionFromMetadata($rawColumnDTOs, $tableMapMetadata);
        } elseif ($this->config->requireTableMapMetadata) {
            throw TableMapMetadataException::incomplete(
                $schemaName,
                $tableName,
                $tableId,
                $columnsAmount,
                $tableMapMetadata === null ? 0 : count($tableMapMetadata->columnNames),
                $eventInfo->binLogCurrent->getBinFileName(),
                $eventInfo->pos
            );
        } else {
            $this->logger->debug('Resolving column metadata for ' . $schemaName . '.' . $tableName . ' from information_schema');
            $fieldDTOCollection = $this->repository->getFields($schemaName, $tableName);
            if (!$fieldDTOCollection->isEmpty() && $fieldDTOCollection->count() !== $columnsAmount) {
                $this->logger->warning(
                    'information_schema lists ' . $fieldDTOCollection->count() . ' columns for ' . $schemaName . '.' . $tableName
                    . ' but the TABLE_MAP_EVENT (table_id ' . $tableId . ') has ' . $columnsAmount
                    . '; the table changed after this event was written, so column names may be misattributed.'
                    . ' Use binlog_row_metadata=FULL with useTableMapMetadata to decode by the event\'s own names.'
                );
            }
        }

        $columnDTOCollection = $this->makeColumnDTOCollection($rawColumnDTOs, $fieldDTOCollection);

        return new TableMap($schemaName, $tableName, $tableId, $columnsAmount, $columnDTOCollection);
    }

    /**
     * @return array<int, ColumnDTO>
     */
    private function readStructuralColumnData(BinaryDataReader $binaryDataReader, string $columnTypes): array
    {
        // structural pass: consumes the per-column metadata bytes and resolves the real
        // ENUM/SET type; the FieldDTO placeholder is discarded, replaced once we know
        // whether we're building fields from the binlog metadata or information_schema
        $rawColumnDTOs = [];
        $columnLength = strlen($columnTypes);
        for ($offset = 0; $offset < $columnLength; ++$offset) {
            $type = ord($columnTypes[$offset]);
            $rawColumnDTOs[$offset] = ColumnDTO::make($type, FieldDTO::makeDummy($offset), $binaryDataReader);
        }

        return $rawColumnDTOs;
    }

    /**
     * @param array<int, ColumnDTO> $rawColumnDTOs
     */
    private function parseMetadata(BinaryDataReader $binaryDataReader, EventInfo $eventInfo, array $rawColumnDTOs): ?TableMapMetadata
    {
        if (!$this->config->useTableMapMetadata) {
            return null;
        }

        $metadataLength = $binaryDataReader->getBinaryDataLength() - ($eventInfo->checkSum ? 4 : 0);
        if ($metadataLength <= 0) {
            $this->logger->debug('No metadata bytes present in TABLE_MAP_EVENT (metadataLength: ' . $metadataLength . ')');

            return null;
        }

        return TableMapMetadata::parse(
            $binaryDataReader,
            $metadataLength,
            array_map(static fn (ColumnDTO $columnDTO): int => $columnDTO->type, $rawColumnDTOs)
        );
    }

    /**
     * Complete means the metadata can fully replace information_schema: a name for every
     * column and the literal list of every ENUM/SET column (FULL writes all of them or,
     * on a server-side failure, none of the optional fields).
     *
     * @param array<int, ColumnDTO> $rawColumnDTOs
     */
    private function isComplete(TableMapMetadata $tableMapMetadata, array $rawColumnDTOs): bool
    {
        if (count($tableMapMetadata->columnNames) !== count($rawColumnDTOs)) {
            return false;
        }

        foreach ($rawColumnDTOs as $offset => $rawColumnDTO) {
            if ($rawColumnDTO->type === ConstFieldType::ENUM && !isset($tableMapMetadata->enumValues[$offset])) {
                return false;
            }
            if ($rawColumnDTO->type === ConstFieldType::SET && !isset($tableMapMetadata->setValues[$offset])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, ColumnDTO> $rawColumnDTOs
     */
    private function makeFieldDTOCollectionFromMetadata(array $rawColumnDTOs, TableMapMetadata $tableMapMetadata): FieldDTOCollection
    {
        $fieldDTOCollection = new FieldDTOCollection();
        foreach ($rawColumnDTOs as $offset => $rawColumnDTO) {
            $fieldDTOCollection->set($offset, $this->makeFieldDTOFromMetadata($offset, $rawColumnDTO->type, $tableMapMetadata));
        }

        return $fieldDTOCollection;
    }

    private function makeFieldDTOFromMetadata(int $offset, int $resolvedType, TableMapMetadata $tableMapMetadata): FieldDTO
    {
        $columnType = match ($resolvedType) {
            ConstFieldType::ENUM => 'enum(' . implode(
                ',',
                array_map(static fn (string $value): string => "'" . $value . "'", $tableMapMetadata->enumValues[$offset] ?? [])
            ) . ')',
            ConstFieldType::SET => 'set(' . implode(
                ',',
                array_map(static fn (string $value): string => "'" . $value . "'", $tableMapMetadata->setValues[$offset] ?? [])
            ) . ')',
            default => $tableMapMetadata->isUnsigned($offset) ? 'unsigned' : '',
        };

        return new FieldDTO(
            $tableMapMetadata->getColumnName($offset, $offset),
            null,
            null,
            '',
            $columnType,
            $tableMapMetadata->isPrimaryKey($offset) ? 'PRI' : ''
        );
    }

    /**
     * @param array<int, ColumnDTO> $rawColumnDTOs
     */
    private function makeColumnDTOCollection(array $rawColumnDTOs, FieldDTOCollection $fieldDTOCollection): ColumnDTOCollection
    {
        $columnDTOCollection = new ColumnDTOCollection();

        // if you drop tables and parse of logs you will get empty scheme
        if ($fieldDTOCollection->isEmpty()) {
            return $columnDTOCollection;
        }

        foreach ($rawColumnDTOs as $offset => $rawColumnDTO) {
            // a column written into this event but no longer in information_schema (dropped after
            // the event was written; only reachable via the information_schema fallback, the binlog
            // metadata path is self-describing) gets a placeholder name but is still decoded by its
            // real binlog type: the type and its storage metadata always come from the event, and
            // skipping it as IGNORE (0 bytes for fixed-width types) desynchronised every later
            // value, e.g. a wrong primary key after dropping the last column
            $fieldDTO = $fieldDTOCollection->offsetExists($offset)
                ? $fieldDTOCollection->offsetGet($offset)
                : FieldDTO::makeDummy($offset);
            $type = $rawColumnDTO->type;

            if ($fieldDTO !== null) {
                $columnDTOCollection->set(
                    $offset,
                    new ColumnDTO(
                        $fieldDTO,
                        $type,
                        $rawColumnDTO->maxLength,
                        $rawColumnDTO->size,
                        $rawColumnDTO->fsp,
                        $rawColumnDTO->lengthSize,
                        $rawColumnDTO->precision,
                        $rawColumnDTO->decimals,
                        $rawColumnDTO->bits,
                        $rawColumnDTO->bytes
                    )
                );
            }
        }

        return $columnDTOCollection;
    }

    private function getColumnsBinarySize(int $columnsAmount): int
    {
        return (int)(($columnsAmount + 7) / 8);
    }
}
