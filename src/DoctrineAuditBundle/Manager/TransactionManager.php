<?php

namespace DH\DoctrineAuditBundle\Manager;

use DateTime;
use DateTimeZone;
use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Event\LifecycleEvent;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Helper\DoctrineHelper;
use DH\DoctrineAuditBundle\Model\Transaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Exception;

class TransactionManager
{
    /**
     * @var \DH\DoctrineAuditBundle\Configuration
     */
    private $configuration;

    /**
     * @var AuditHelper
     */
    private $helper;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(Configuration $configuration, AuditHelper $helper)
    {
        $this->configuration = $configuration;
        $this->helper = $helper;
        $this->em = $this->configuration->getEntityManager();
    }

    /**
     * @return \DH\DoctrineAuditBundle\Configuration
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    /**
     * @param array $payload
     */
    public function notify(array $payload): void
    {
        $dispatcher = $this->configuration->getEventDispatcher();

        if ($this->configuration->isPre43Dispatcher()) {
            // Symfony 3.x
            $dispatcher->dispatch(LifecycleEvent::class, new LifecycleEvent($payload));
        } else {
            // Symfony 4.x
            $dispatcher->dispatch(new LifecycleEvent($payload));
        }
    }

    /**
     * @param Transaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function process(Transaction $transaction): void
    {
        $this->processInsertions($transaction);
        $this->processUpdates($transaction);
        $this->processAssociations($transaction);
        $this->processDissociations($transaction);
        $this->processDeletions($transaction);
    }

    /**
     * Adds an insert entry to the audit table.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param array                  $ch
     * @param string                 $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function insert(EntityManagerInterface $em, $entity, array $ch, string $transactionHash): void
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => 'insert',
            'blame' => $this->helper->blame(),
            'diff' => $this->helper->diff($em, $entity, $ch),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->helper->id($em, $entity),
            'transaction_hash' => $transactionHash,
            'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
            'entity' => $meta->getName(),
        ]);
    }

    /**
     * Adds an update entry to the audit table.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param array                  $ch
     * @param string                 $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function update(EntityManagerInterface $em, $entity, array $ch, string $transactionHash): void
    {
        $diff = $this->helper->diff($em, $entity, $ch);
        if (0 === \count($diff)) {
            return; // if there is no entity diff, do not log it
        }
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => 'update',
            'blame' => $this->helper->blame(),
            'diff' => $diff,
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->helper->id($em, $entity),
            'transaction_hash' => $transactionHash,
            'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
            'entity' => $meta->getName(),
        ]);
    }

    /**
     * Adds a remove entry to the audit table.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param mixed                  $id
     * @param string                 $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function remove(EntityManagerInterface $em, $entity, $id, string $transactionHash): void
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => 'remove',
            'blame' => $this->helper->blame(),
            'diff' => $this->helper->summarize($em, $entity, $id),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $id,
            'transaction_hash' => $transactionHash,
            'discriminator' => $this->getDiscriminator($entity, $meta->inheritanceType),
            'entity' => $meta->getName(),
        ]);
    }

    /**
     * Adds an association entry to the audit table.
     *
     * @param EntityManagerInterface $em
     * @param object                 $source
     * @param object                 $target
     * @param array                  $mapping
     * @param string                 $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function associate(EntityManagerInterface $em, $source, $target, array $mapping, string $transactionHash): void
    {
        $this->associateOrDissociate('associate', $em, $source, $target, $mapping, $transactionHash);
    }

    /**
     * Adds a dissociation entry to the audit table.
     *
     * @param EntityManagerInterface $em
     * @param object                 $source
     * @param object                 $target
     * @param array                  $mapping
     * @param string                 $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function dissociate(EntityManagerInterface $em, $source, $target, array $mapping, string $transactionHash): void
    {
        $this->associateOrDissociate('dissociate', $em, $source, $target, $mapping, $transactionHash);
    }

    /**
     * Set the value of helper.
     *
     * @param AuditHelper $helper
     */
    public function setHelper(AuditHelper $helper): void
    {
        $this->helper = $helper;
    }

    /**
     * Get the value of helper.
     *
     * @return AuditHelper
     */
    public function getHelper(): AuditHelper
    {
        return $this->helper;
    }

    /**
     * @param Transaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processInsertions(Transaction $transaction): void
    {
        $uow = $this->em->getUnitOfWork();
        foreach ($transaction->getInserted() as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($this->em, $entity, $ch, $transaction->getTransactionHash());
        }
    }

    /**
     * @param Transaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processUpdates(Transaction $transaction): void
    {
        $uow = $this->em->getUnitOfWork();
        foreach ($transaction->getUpdated() as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($this->em, $entity, $ch, $transaction->getTransactionHash());
        }
    }

    /**
     * @param Transaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processAssociations(Transaction $transaction): void
    {
        foreach ($transaction->getAssociated() as list($source, $target, $mapping)) {
            $this->associate($this->em, $source, $target, $mapping, $transaction->getTransactionHash());
        }
    }

    /**
     * @param Transaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processDissociations(Transaction $transaction): void
    {
        foreach ($transaction->getDissociated() as list($source, $target, $id, $mapping)) {
            $this->dissociate($this->em, $source, $target, $mapping, $transaction->getTransactionHash());
        }
    }

    /**
     * @param Transaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function processDeletions(Transaction $transaction): void
    {
        foreach ($transaction->getRemoved() as list($entity, $id)) {
            $this->remove($this->em, $entity, $id, $transaction->getTransactionHash());
        }
    }

    /**
     * @param EntityManagerInterface $em
     *
     * @return EntityManagerInterface
     */
    public function selectStorageSpace(EntityManagerInterface $em): EntityManagerInterface
    {
        return $this->configuration->getEntityManager() ?? $em;
    }

    //

    public function populate(Transaction $transaction): void
    {
        $uow = $this->em->getUnitOfWork();

        $this->populateWithScheduledInsertions($transaction, $uow);
        $this->populateWithScheduledUpdates($transaction, $uow);
        $this->populateWithScheduledDeletions($transaction, $uow, $this->em);
        $this->populateWithScheduledCollectionUpdates($transaction, $uow, $this->em);
        $this->populateWithScheduledCollectionDeletions($transaction, $uow, $this->em);
    }

    /**
     * @param UnitOfWork $uow
     */
    private function populateWithScheduledInsertions(Transaction $transaction, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $transaction->trackAuditEvent(Transaction::INSERT, [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ]);
            }
        }
    }

    /**
     * @param UnitOfWork $uow
     */
    private function populateWithScheduledUpdates(Transaction $transaction, UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $transaction->trackAuditEvent(Transaction::UPDATE, [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ]);
            }
        }
    }

    /**
     * @param UnitOfWork             $uow
     * @param EntityManagerInterface $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function populateWithScheduledDeletions(Transaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $uow->initializeObject($entity);
                $transaction->trackAuditEvent(Transaction::REMOVE, [
                    $entity,
                    $this->helper->id($em, $entity),
                ]);
            }
        }
    }

    /**
     * @param UnitOfWork             $uow
     * @param EntityManagerInterface $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function populateWithScheduledCollectionUpdates(Transaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->getInsertDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $transaction->trackAuditEvent(Transaction::ASSOCIATE, [
                            $collection->getOwner(),
                            $entity,
                            $mapping,
                        ]);
                    }
                }
                foreach ($collection->getDeleteDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $transaction->trackAuditEvent(Transaction::DISSOCIATE, [
                            $collection->getOwner(),
                            $entity,
                            $this->helper->id($em, $entity),
                            $mapping,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * @param UnitOfWork             $uow
     * @param EntityManagerInterface $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function populateWithScheduledCollectionDeletions(Transaction $transaction, UnitOfWork $uow, EntityManagerInterface $em): void
    {
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->toArray() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $transaction->trackAuditEvent(Transaction::DISSOCIATE, [
                            $collection->getOwner(),
                            $entity,
                            $this->helper->id($em, $entity),
                            $mapping,
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Adds an association entry to the audit table.
     *
     * @param string                 $type
     * @param EntityManagerInterface $em
     * @param object                 $source
     * @param object                 $target
     * @param array                  $mapping
     * @param string                 $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function associateOrDissociate(string $type, EntityManagerInterface $em, $source, $target, array $mapping, string $transactionHash): void
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($source));
        $data = [
            'action' => $type,
            'blame' => $this->helper->blame(),
            'diff' => [
                'source' => $this->helper->summarize($em, $source),
                'target' => $this->helper->summarize($em, $target),
            ],
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->helper->id($em, $source),
            'transaction_hash' => $transactionHash,
            'discriminator' => $this->getDiscriminator($source, $meta->inheritanceType),
            'entity' => $meta->getName(),
        ];

        if (isset($mapping['joinTable']['name'])) {
            $data['diff']['table'] = $mapping['joinTable']['name'];
        }

        $this->audit($data);
    }

    /**
     * Adds an entry to the audit table.
     *
     * @param array $data
     *
     * @throws Exception
     */
    private function audit(array $data): void
    {
        $schema = $data['schema'] ? $data['schema'].'.' : '';
        $auditTable = $schema.$this->configuration->getTablePrefix().$data['table'].$this->configuration->getTableSuffix();
        $dt = new DateTime('now', new DateTimeZone($this->getConfiguration()->getTimezone()));

        $payload = [
            'entity' => $data['entity'],
            'table' => $auditTable,
            'type' => $data['action'],
            'object_id' => (string) $data['id'],
            'discriminator' => $data['discriminator'],
            'transaction_hash' => (string) $data['transaction_hash'],
            'diffs' => json_encode($data['diff']),
            'blame_id' => $data['blame']['user_id'],
            'blame_user' => $data['blame']['username'],
            'blame_user_fqdn' => $data['blame']['user_fqdn'],
            'blame_user_firewall' => $data['blame']['user_firewall'],
            'ip' => $data['blame']['client_ip'],
            'created_at' => $dt->format('Y-m-d H:i:s'),
        ];

        // send an `AuditEvent` event
        $this->notify($payload);
    }

    /**
     * @param object $entity
     * @param int    $inheritanceType
     *
     * @return null|string
     */
    private function getDiscriminator($entity, int $inheritanceType): ?string
    {
        return ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $inheritanceType ? DoctrineHelper::getRealClassName($entity) : null;
    }
}
