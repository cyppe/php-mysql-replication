<?php

declare(strict_types=1);

namespace MySQLReplication\Tests\Unit\Event;

use MySQLReplication\BinLog\BinLogCurrent;
use MySQLReplication\BinLog\BinLogServerInfo;
use MySQLReplication\BinLog\BinLogSocketConnect;
use MySQLReplication\Config\Config;
use MySQLReplication\Definitions\ConstEventType;
use MySQLReplication\Event\DTO\EventDTO;
use MySQLReplication\Event\DTO\TableMapDTO;
use MySQLReplication\Event\Event;
use MySQLReplication\Event\EventInfo;
use MySQLReplication\Event\EventObserverInterface;
use MySQLReplication\Event\RowEvent\RowEvent;
use MySQLReplication\Event\RowEvent\RowEventFactory;
use MySQLReplication\Event\RowEvent\TableMap;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class EventObserverTest extends TestCase
{
    public function testObserverSeesIgnoredXidWithoutAnAllocatedDto(): void
    {
        $observer = $this->createMock(EventObserverInterface::class);
        $observer->expects(self::once())->method('onEventProcessed')->with(
            self::callback(static fn (EventInfo $eventInfo): bool => $eventInfo->type === ConstEventType::XID_EVENT->value),
            null,
            false
        );

        $this->makeEvent(
            ConstEventType::XID_EVENT->value,
            false,
            $this->createStub(EventDispatcherInterface::class),
            $observer
        )->consume();
    }

    public function testObserverSeesTableMapRejectedByTableFiltering(): void
    {
        $rowEvent = $this->createMock(RowEvent::class);
        $rowEvent->expects(self::once())->method('makeTableMapDTO')->willReturn(null);
        $rowEventFactory = $this->createMock(RowEventFactory::class);
        $rowEventFactory->expects(self::once())->method('makeRowEvent')->willReturn($rowEvent);

        $observer = $this->createMock(EventObserverInterface::class);
        $observer->expects(self::once())->method('onEventProcessed')->with(
            self::callback(static fn (EventInfo $eventInfo): bool => $eventInfo->type === ConstEventType::TABLE_MAP_EVENT->value),
            null,
            false
        );

        $this->makeEvent(
            ConstEventType::TABLE_MAP_EVENT->value,
            true,
            $this->createStub(EventDispatcherInterface::class),
            $observer,
            $rowEventFactory
        )->consume();
    }

    public function testObserverReceivesParsedMandatoryEventWhenDispatchFilteringRejectsIt(): void
    {
        $tableMapDTO = new TableMapDTO(
            new EventInfo(1, ConstEventType::TABLE_MAP_EVENT->value, 1, 19, '4', 0, false, new BinLogCurrent()),
            $this->createStub(TableMap::class)
        );
        $rowEvent = $this->createStub(RowEvent::class);
        $rowEvent->method('makeTableMapDTO')->willReturn($tableMapDTO);
        $rowEventFactory = $this->createStub(RowEventFactory::class);
        $rowEventFactory->method('makeRowEvent')->willReturn($rowEvent);

        $observer = $this->createMock(EventObserverInterface::class);
        $observer->expects(self::once())->method('onEventProcessed')->with(
            self::callback(static fn (EventInfo $eventInfo): bool => $eventInfo->type === ConstEventType::TABLE_MAP_EVENT->value),
            $tableMapDTO,
            false
        );

        $this->makeEvent(
            ConstEventType::TABLE_MAP_EVENT->value,
            false,
            $this->createStub(EventDispatcherInterface::class),
            $observer,
            $rowEventFactory
        )->consume();
    }

    public function testObserverRunsAfterSubscriberDispatchAndBeforeSemiSyncAck(): void
    {
        $order = [];
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willReturnCallback(
            static function (EventDTO $eventDTO, ?string $eventName = null) use (&$order): EventDTO {
                $order[] = 'dispatch';

                return $eventDTO;
            }
        );
        $observer = $this->createMock(EventObserverInterface::class);
        $observer->expects(self::once())->method('onEventProcessed')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'observer';
            }
        );

        $socket = $this->socket(ConstEventType::FORMAT_DESCRIPTION_EVENT->value, true);
        $socket->expects(self::once())->method('sendSemiSyncAck')->willReturnCallback(
            static function () use (&$order): void {
                $order[] = 'ack';
            }
        );

        $this->makeEventWithSocket($socket, true, $dispatcher, $observer)->consume();

        self::assertSame(['dispatch', 'observer', 'ack'], $order);
    }

    public function testObserverDoesNotAdvanceAfterSubscriberFailure(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects(self::once())->method('dispatch')->willThrowException(new RuntimeException('subscriber failed'));
        $observer = $this->createMock(EventObserverInterface::class);
        $observer->expects(self::never())->method('onEventProcessed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('subscriber failed');

        $this->makeEvent(ConstEventType::FORMAT_DESCRIPTION_EVENT->value, true, $dispatcher, $observer)->consume();
    }

    public function testParserFailureDoesNotNotifyObserver(): void
    {
        $rowEvent = $this->createMock(RowEvent::class);
        $rowEvent->method('makeTableMapDTO')->willThrowException(new RuntimeException('parse failed'));
        $rowEventFactory = $this->createStub(RowEventFactory::class);
        $rowEventFactory->method('makeRowEvent')->willReturn($rowEvent);

        $observer = $this->createMock(EventObserverInterface::class);
        $observer->expects(self::never())->method('onEventProcessed');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('parse failed');

        $this->makeEvent(
            ConstEventType::TABLE_MAP_EVENT->value,
            true,
            $this->createStub(EventDispatcherInterface::class),
            $observer,
            $rowEventFactory
        )->consume();
    }

    public function testObserverFailurePropagatesBeforeSemiSyncAck(): void
    {
        $observer = $this->createStub(EventObserverInterface::class);
        $observer->method('onEventProcessed')->willThrowException(new RuntimeException('observer failed'));

        $socket = $this->socket(ConstEventType::FORMAT_DESCRIPTION_EVENT->value, true);
        $socket->expects(self::never())->method('sendSemiSyncAck');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('observer failed');

        $this->makeEventWithSocket($socket, true, $this->createStub(EventDispatcherInterface::class), $observer)->consume();
    }

    private function makeEvent(
        int $type,
        bool $permitted,
        EventDispatcherInterface $dispatcher,
        EventObserverInterface $observer,
        ?RowEventFactory $rowEventFactory = null
    ): Event {
        return $this->makeEventWithSocket($this->socket($type), $permitted, $dispatcher, $observer, $rowEventFactory);
    }

    private function makeEventWithSocket(
        BinLogSocketConnect $socket,
        bool $permitted,
        EventDispatcherInterface $dispatcher,
        EventObserverInterface $observer,
        ?RowEventFactory $rowEventFactory = null
    ): Event {
        $config = $this->createStub(Config::class);
        $config->method('checkEvent')->willReturn($permitted);

        return new Event(
            $socket,
            $rowEventFactory ?? $this->createStub(RowEventFactory::class),
            $dispatcher,
            $this->createStub(CacheInterface::class),
            $config,
            $this->createStub(BinLogServerInfo::class),
            $observer
        );
    }

    private function socket(int $type, bool $semiSync = false): BinLogSocketConnect
    {
        $socket = $this->createMock(BinLogSocketConnect::class);
        $socket->method('getResponse')->willReturn($this->rawPacket($type, $semiSync));
        $socket->method('getSemiSyncEnabled')->willReturn($semiSync);
        $socket->method('getCheckSum')->willReturn(false);
        $socket->method('getBinLogCurrent')->willReturn(new BinLogCurrent());

        return $socket;
    }

    private function rawPacket(int $type, bool $semiSync): string
    {
        $packet = chr(0);

        if ($semiSync) {
            $packet .= chr(0xef) . chr(1);
        }

        return $packet
            . pack('V', 1)
            . pack('C', $type)
            . pack('V', 1)
            . pack('V', 19)
            . pack('V', 4)
            . pack('v', 0);
    }
}
