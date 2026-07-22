<?php

declare(strict_types=1);

namespace MySQLReplication\Event\RowEvent;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\Config\Config;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Repository\RepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

class RowEventBuilder
{
    private BinaryDataReader $binaryDataReader;
    private EventInfo $eventInfo;
    private readonly TableMapCache $tableMapCache;
    private readonly TableMapBuilder $tableMapBuilder;

    public function __construct(
        private readonly RepositoryInterface $repository,
        private readonly CacheInterface $cache,
        private readonly Config $config,
        private readonly BinLogServerInfo $binLogServerInfo,
        private readonly LoggerInterface $logger
    ) {
        $this->tableMapCache = new TableMapCache($cache);
        $this->tableMapBuilder = new TableMapBuilder($repository, $config, $logger);
    }

    public function withBinaryDataReader(BinaryDataReader $binaryDataReader): void
    {
        $this->binaryDataReader = $binaryDataReader;
    }

    public function build(): RowEvent
    {
        return new RowEvent(
            $this->binaryDataReader,
            $this->eventInfo,
            $this->repository,
            $this->cache,
            $this->config,
            $this->binLogServerInfo,
            $this->logger,
            $this->tableMapCache,
            $this->tableMapBuilder
        );
    }

    public function withEventInfo(EventInfo $eventInfo): void
    {
        $this->eventInfo = $eventInfo;
    }
}
