<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Retention;

/**
 * Asks for audit entries to be pruned according to the configured retention policies.
 *
 * Dispatched periodically by RetentionSchedule when "providers.doctrine.retention.schedule" is set.
 */
final readonly class PruneAuditLogsMessage {}
