<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Retention;

use DH\AuditorBundle\Retention\PruneAuditLogsMessage;
use DH\AuditorBundle\Retention\RetentionSchedule;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Trigger\CronExpressionTrigger;

/**
 * @internal
 */
#[Small]
final class RetentionScheduleTest extends TestCase
{
    public function testScheduleDispatchesThePruneMessageOnTheCronExpression(): void
    {
        $messages = new RetentionSchedule('0 3 * * *')->getSchedule()->getRecurringMessages();

        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertInstanceOf(CronExpressionTrigger::class, $message->getTrigger());
        $this->assertSame('0 3 * * *', (string) $message->getTrigger());

        $dispatched = iterator_to_array($message->getProvider()->getMessages(new MessageContext('name', 'id', $message->getTrigger(), new \DateTimeImmutable())));
        $this->assertCount(1, $dispatched);
        $this->assertInstanceOf(PruneAuditLogsMessage::class, $dispatched[0]);
    }
}
