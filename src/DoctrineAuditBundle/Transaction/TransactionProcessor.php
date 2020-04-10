<?php

namespace DH\DoctrineAuditBundle\Transaction;

use DateTime;
use DateTimeZone;
use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Event\LifecycleEvent;
use DH\DoctrineAuditBundle\Helper\DoctrineHelper;
use DH\DoctrineAuditBundle\Model\Transaction;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;

class TransactionProcessor
{
    use AuditTrait;

    /**
     * @var Configuration
     */
    private $configuration;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
        $this->em = $this->configuration->getEntityManager();
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
     * @param array $payload
     */
    private function notify(array $payload): void
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
    private function insert(EntityManagerInterface $em, $entity, array $ch, string $transactionHash): void
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => 'insert',
            'blame' => $this->blame(),
            'diff' => $this->diff($em, $entity, $ch),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->id($em, $entity),
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
    private function update(EntityManagerInterface $em, $entity, array $ch, string $transactionHash): void
    {
        $diff = $this->diff($em, $entity, $ch);
        if (0 === \count($diff)) {
            return; // if there is no entity diff, do not log it
        }
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => 'update',
            'blame' => $this->blame(),
            'diff' => $diff,
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->id($em, $entity),
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
    private function remove(EntityManagerInterface $em, $entity, $id, string $transactionHash): void
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $this->audit([
            'action' => 'remove',
            'blame' => $this->blame(),
            'diff' => $this->summarize($em, $entity, $id),
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
    private function associate(EntityManagerInterface $em, $source, $target, array $mapping, string $transactionHash): void
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
    private function dissociate(EntityManagerInterface $em, $source, $target, array $mapping, string $transactionHash): void
    {
        $this->associateOrDissociate('dissociate', $em, $source, $target, $mapping, $transactionHash);
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
        foreach ($transaction->getInserted() as [$entity, $ch]) {
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
        foreach ($transaction->getUpdated() as [$entity, $ch]) {
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
        foreach ($transaction->getAssociated() as [$source, $target, $mapping]) {
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
        foreach ($transaction->getDissociated() as [$source, $target, $id, $mapping]) {
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
        foreach ($transaction->getRemoved() as [$entity, $id]) {
            $this->remove($this->em, $entity, $id, $transaction->getTransactionHash());
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
            'blame' => $this->blame(),
            'diff' => [
                'source' => $this->summarize($em, $source),
                'target' => $this->summarize($em, $target),
            ],
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->id($em, $source),
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
        $dt = new DateTime('now', new DateTimeZone($this->configuration->getTimezone()));

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
