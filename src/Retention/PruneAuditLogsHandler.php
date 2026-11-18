<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Retention;

use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\Model\RetentionPolicy;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Retention\RetentionPruner;
use DH\AuditorBundle\Tests\Retention\PruneAuditLogsHandlerTest;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Lock\Store\SemaphoreStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Prunes audit entries according to the retention policies only.
 *
 * Unlike "audit:clean", there is no implicit global retention period: an entity without a retention policy
 * is left untouched, unless a default max age is explicitly configured.
 *
 * Every policy is validated before anything is deleted, and the "audit:clean" lock is shared so that a scheduled
 * run and a manual one never overlap on the same host.
 *
 * @see PruneAuditLogsHandlerTest
 */
final readonly class PruneAuditLogsHandler
{
    public const string LOCK_NAME = 'audit:clean';

    private LockFactory $lockFactory;

    private LoggerInterface $logger;

    /**
     * @throws InvalidArgumentException when the default max age is invalid
     */
    public function __construct(
        private DoctrineProvider $provider,
        private ?string $defaultMaxAge = null,
        ?LoggerInterface $logger = null,
        ?LockFactory $lockFactory = null,
    ) {
        $this->logger = $logger ?? new NullLogger();

        if (null !== $defaultMaxAge) {
            new RetentionPolicy($defaultMaxAge);
        }

        $this->lockFactory = $lockFactory ?? new LockFactory(SemaphoreStore::isSupported() ? new SemaphoreStore() : new FlockStore());
    }

    /**
     * @throws InvalidArgumentException when a retention policy is invalid (nothing is deleted then)
     */
    #[AsMessageHandler(handles: PruneAuditLogsMessage::class)]
    public function __invoke(PruneAuditLogsMessage $message): void
    {
        $lock = $this->lockFactory->createLock(self::LOCK_NAME);
        if (!$lock->acquire()) {
            $this->logger->info('Audit logs pruning skipped: "audit:clean" is already running.');

            return;
        }

        try {
            $this->prune();
        } finally {
            $lock->release();
        }
    }

    private function prune(): void
    {
        $pruner = new RetentionPruner($this->provider);

        $entities = $pruner->getAuditableEntities();

        // Validates every policy before deleting anything.
        $policies = $pruner->getPolicies($entities);
        $defaultCutoff = null === $this->defaultMaxAge ? null : new RetentionPolicy($this->defaultMaxAge)->getCutoff($pruner->now());

        foreach ($entities as $entity) {
            $policy = $policies[$entity] ?? null;
            if (null === $policy && !$defaultCutoff instanceof \DateTimeImmutable) {
                continue;
            }

            $statements = $pruner->pruneEntity($entity, $policy, $defaultCutoff);
            $this->logger->info('Audit logs of "{entity}" pruned.', ['entity' => $entity, 'statements' => $statements]);
        }
    }
}
