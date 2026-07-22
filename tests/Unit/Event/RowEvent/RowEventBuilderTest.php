<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event\RowEvent;

use MySQLReplication\BinaryDataReader\BinaryDataReader;
use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\Config\ConfigBuilder;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\RowEvent\RowEvent;
use MySQLReplication\Event\RowEvent\RowEventBuilder;
use MySQLReplication\Repository\RepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use ReflectionProperty;

class RowEventBuilderTest extends TestCase
{
    public function testRowEventRetainsItsExistingPositionalConstructor(): void
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('binlog.001');

        $rowEvent = new RowEvent(
            new BinaryDataReader(''),
            new EventInfo(1, 0, 1, 19, '4', 0, false, $current),
            $this->createStub(RepositoryInterface::class),
            $this->createStub(CacheInterface::class),
            (new ConfigBuilder())->build(),
            $this->createStub(BinLogServerInfo::class),
            new NullLogger()
        );

        self::assertInstanceOf(RowEvent::class, $rowEvent);
    }

    public function testReusesTableMapHelpersAcrossEvents(): void
    {
        $builder = new RowEventBuilder(
            $this->createStub(RepositoryInterface::class),
            $this->createStub(CacheInterface::class),
            (new ConfigBuilder())->build(),
            $this->createStub(BinLogServerInfo::class),
            new NullLogger()
        );

        $first = $this->buildRowEvent($builder, '4');
        $second = $this->buildRowEvent($builder, '8');

        foreach (['tableMapCache', 'tableMapBuilder'] as $propertyName) {
            $property = new ReflectionProperty(RowEvent::class, $propertyName);
            self::assertSame($property->getValue($first), $property->getValue($second));
        }
    }

    private function buildRowEvent(RowEventBuilder $builder, string $position): RowEvent
    {
        $current = new BinLogCurrent();
        $current->setBinFileName('binlog.001');
        $current->setBinLogPosition($position);

        $builder->withBinaryDataReader(new BinaryDataReader(''));
        $builder->withEventInfo(new EventInfo(1, 0, 1, 19, $position, 0, false, $current));

        return $builder->build();
    }
}
