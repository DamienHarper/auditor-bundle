<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Retention;

use DH\AuditorBundle\Tests\Retention\RetentionScheduleTest;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

/**
 * Symfony Scheduler schedule dispatching PruneAuditLogsMessage on the configured cron expression.
 *
 * Only registered when "providers.doctrine.retention.schedule" is set; run it with
 * "bin/console messenger:consume scheduler_dh_auditor_retention".
 *
 * @see RetentionScheduleTest
 */
final class RetentionSchedule implements ScheduleProviderInterface
{
    public const string NAME = 'dh_auditor_retention';

    private ?Schedule $schedule = null;

    public function __construct(private readonly string $cronExpression) {}

    public function getSchedule(): Schedule
    {
        return $this->schedule ??= new Schedule()->add(RecurringMessage::cron($this->cronExpression, new PruneAuditLogsMessage()));
    }
}
