<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event;

use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Config\Config;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\Event;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\EventObserverInterface;
use MySQLReplication\Event\RowEvent\RowEventFactory;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * The event header's timestamp, server id, size and next position are unsigned 32-bit fields.
 */
class EventConsumeHeaderTest extends TestCase
{
    public function testHeaderFieldsAboveTwoGibibytesStayPositiveAndAdvanceThePosition(): void
    {
        $current = new BinLogCurrent();
        $current->setBinLogPosition('2147483000');

        $socket = $this->createStub(BinLogSocketConnect::class);
        $socket->method('getResponse')->willReturn(
            chr(0)
            . pack('V', 0xF0000000)   // timestamp
            . chr(0)                  // type UNKNOWN_EVENT, ignored via checkEvent()
            . pack('V', 0xFFFFFFFE)   // server id
            . pack('V', 19)           // event size
            . pack('V', 0x90000000)   // next position, past 2 GiB
            . pack('v', 0)
        );
        $socket->method('getSemiSyncEnabled')->willReturn(false);
        $socket->method('getBinLogCurrent')->willReturn($current);
        $socket->method('getCheckSum')->willReturn(false);

        $config = $this->createStub(Config::class);
        $config->method('checkEvent')->willReturn(false);

        $observer = new class() implements EventObserverInterface {
            public ?EventInfo $eventInfo = null;

            public function onEventProcessed(EventInfo $eventInfo, ?EventDTO $eventDTO, bool $dispatched): void
            {
                $this->eventInfo = $eventInfo;
            }
        };

        (new Event(
            $socket,
            $this->createStub(RowEventFactory::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(CacheInterface::class),
            $config,
            $this->createStub(BinLogServerInfo::class),
            $observer
        ))->consume();

        self::assertNotNull($observer->eventInfo);
        self::assertSame(0xF0000000, $observer->eventInfo->timestamp);
        self::assertSame(0xFFFFFFFE, $observer->eventInfo->serverId);
        self::assertSame('2415919104', $observer->eventInfo->pos);
        self::assertSame('2415919104', $current->getBinLogPosition());
    }
}
