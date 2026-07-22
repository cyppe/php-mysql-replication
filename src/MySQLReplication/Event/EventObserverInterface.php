<?php

declare(strict_types=1);

namespace MySQLReplication\Event;

use MySQLReplication\Event\DTO\EventDTO;

/**
 * Observes a binlog event only after parsing and synchronous subscriber dispatch
 * completed successfully. Filtered events may have a null DTO, or the parsed
 * DTO when internal state handling required one. The dispatched flag confirms
 * that the synchronous event dispatcher returned successfully; it does not
 * imply that a listener was registered. This allows consumers to track
 * liveness and safe transaction boundaries without allocating an application
 * event for every ordinary filtered packet.
 */
interface EventObserverInterface
{
    public function onEventProcessed(EventInfo $eventInfo, ?EventDTO $eventDTO, bool $dispatched): void;
}
