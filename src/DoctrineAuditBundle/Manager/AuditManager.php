<?php

namespace DH\DoctrineAuditBundle\Manager;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Event\LifecycleEvent;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

class AuditManager
{
    /**
     * @var \DH\DoctrineAuditBundle\AuditConfiguration
     */
    private $configuration;

    /**
     * @var AuditHelper
     */
    private $helper;

    public function __construct(AuditConfiguration $configuration, AuditHelper $helper)
    {
        $this->configuration = $configuration;
        $this->helper = $helper;
    }

    /**
     * @return \DH\DoctrineAuditBundle\AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * @param array $payload
     */
    public function notify(array $payload): void
    {
        $this->configuration->getEventDispatcher()->dispatch(new LifecycleEvent($payload));
    }

    /**
     * @param AuditTransaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function process(AuditTransaction $transaction): void
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
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'insert',
            'blame' => $this->helper->blame(),
            'diff' => $this->helper->diff($em, $entity, $ch),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->helper->id($em, $entity),
            'transaction_hash' => $transactionHash,
            'discriminator' => ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $meta->inheritanceType ? \get_class($entity) : null,
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
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'update',
            'blame' => $this->helper->blame(),
            'diff' => $diff,
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $this->helper->id($em, $entity),
            'transaction_hash' => $transactionHash,
            'discriminator' => ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $meta->inheritanceType ? \get_class($entity) : null,
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
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'remove',
            'blame' => $this->helper->blame(),
            'diff' => $this->helper->summarize($em, $entity, $id),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
            'id' => $id,
            'transaction_hash' => $transactionHash,
            'discriminator' => ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $meta->inheritanceType ? \get_class($entity) : null,
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
        $meta = $em->getClassMetadata(\get_class($source));
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
            'discriminator' => ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE === $meta->inheritanceType ? \get_class($source) : null,
        ];

        if (isset($mapping['joinTable']['name'])) {
            $data['diff']['table'] = $mapping['joinTable']['name'];
        }

        $this->audit($em, $data);
    }

    /**
     * Adds an entry to the audit table.
     *
     * @param EntityManagerInterface $em
     * @param array                  $data
     *
     * @throws \Exception
     */
    private function audit(EntityManagerInterface $em, array $data): void
    {
        $schema = $data['schema'] ? $data['schema'].'.' : '';
        $auditTable = $schema.$this->configuration->getTablePrefix().$data['table'].$this->configuration->getTableSuffix();
        $dt = new \DateTime('now', new \DateTimeZone($this->getConfiguration()->getTimezone()));

        $payload = [
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
     * @param AuditTransaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processInsertions(AuditTransaction $transaction): void
    {
        $em = $transaction->getEntityManager();
        $uow = $em->getUnitOfWork();
        foreach ($transaction->getInserted() as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($em, $entity, $ch, $transaction->getTransactionHash());
        }
    }

    /**
     * @param AuditTransaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processUpdates(AuditTransaction $transaction): void
    {
        $em = $transaction->getEntityManager();
        $uow = $em->getUnitOfWork();
        foreach ($transaction->getUpdated() as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($em, $entity, $ch, $transaction->getTransactionHash());
        }
    }

    /**
     * @param AuditTransaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processAssociations(AuditTransaction $transaction): void
    {
        $em = $transaction->getEntityManager();
        foreach ($transaction->getAssociated() as list($source, $target, $mapping)) {
            $this->associate($em, $source, $target, $mapping, $transaction->getTransactionHash());
        }
    }

    /**
     * @param AuditTransaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processDissociations(AuditTransaction $transaction): void
    {
        $em = $transaction->getEntityManager();
        foreach ($transaction->getDissociated() as list($source, $target, $id, $mapping)) {
            $this->dissociate($em, $source, $target, $mapping, $transaction->getTransactionHash());
        }
    }

    /**
     * @param AuditTransaction $transaction
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processDeletions(AuditTransaction $transaction): void
    {
        $em = $transaction->getEntityManager();
        foreach ($transaction->getRemoved() as list($entity, $id)) {
            $this->remove($em, $entity, $id, $transaction->getTransactionHash());
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
}
