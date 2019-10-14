<?php

namespace DH\DoctrineAuditBundle\Manager;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\AuditEntry;
use DH\DoctrineAuditBundle\Exception\AccessDeniedException;
use DH\DoctrineAuditBundle\Exception\InvalidArgumentException;
use DH\DoctrineAuditBundle\Helper\AuditHelper;
use DH\DoctrineAuditBundle\Reader\AuditReader;
use Doctrine\ORM\EntityManager;
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
     * @param EntityManager $em
     * @param object        $entity
     * @param array         $ch
     * @param string        $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function insert(EntityManager $em, $entity, array $ch, string $transactionHash): void
    {
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
     * @param EntityManager $em
     * @param object        $entity
     * @param array         $ch
     * @param string        $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function update(EntityManager $em, $entity, array $ch, string $transactionHash): void
    {
        $diff = $this->helper->diff($em, $entity, $ch);
        if (0 === \count($diff)) {
            return; // if there is no entity diff, do not log it
        }
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
     * @param EntityManager $em
     * @param object        $entity
     * @param mixed         $id
     * @param string        $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function remove(EntityManager $em, $entity, $id, string $transactionHash): void
    {
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
     * @param EntityManager $em
     * @param object        $source
     * @param object        $target
     * @param array         $mapping
     * @param string        $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function associate(EntityManager $em, $source, $target, array $mapping, string $transactionHash): void
    {
        $this->associateOrDissociate('associate', $em, $source, $target, $mapping, $transactionHash);
    }

    /**
     * Adds a dissociation entry to the audit table.
     *
     * @param EntityManager $em
     * @param object        $source
     * @param object        $target
     * @param array         $mapping
     * @param string        $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function dissociate(EntityManager $em, $source, $target, array $mapping, string $transactionHash): void
    {
        $this->associateOrDissociate('dissociate', $em, $source, $target, $mapping, $transactionHash);
    }

    /**
     * Adds an association entry to the audit table.
     *
     * @param string        $type
     * @param EntityManager $em
     * @param object        $source
     * @param object        $target
     * @param array         $mapping
     * @param string        $transactionHash
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function associateOrDissociate(string $type, EntityManager $em, $source, $target, array $mapping, string $transactionHash): void
    {
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
     * @param EntityManager $em
     * @param array         $data
     *
     * @throws \Doctrine\DBAL\DBALException
     */
    private function audit(EntityManager $em, array $data): void
    {
        $schema = $data['schema'] ? $data['schema'].'.' : '';
        $auditTable = $schema.$this->configuration->getTablePrefix().$data['table'].$this->configuration->getTableSuffix();
        $fields = [
            'type' => ':type',
            'object_id' => ':object_id',
            'discriminator' => ':discriminator',
            'transaction_hash' => ':transaction_hash',
            'diffs' => ':diffs',
            'blame_id' => ':blame_id',
            'blame_user' => ':blame_user',
            'blame_user_fqdn' => ':blame_user_fqdn',
            'blame_user_firewall' => ':blame_user_firewall',
            'ip' => ':ip',
            'created_at' => ':created_at',
        ];

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $auditTable,
            implode(', ', array_keys($fields)),
            implode(', ', array_values($fields))
        );

        $storage = $this->selectStorageSpace($em);
        $statement = $storage->getConnection()->prepare($query);

        $dt = new \DateTime('now', new \DateTimeZone($this->getConfiguration()->getTimezone()));
        $statement->bindValue('type', $data['action']);
        $statement->bindValue('object_id', (string) $data['id']);
        $statement->bindValue('discriminator', $data['discriminator']);
        $statement->bindValue('transaction_hash', (string) $data['transaction_hash']);
        $statement->bindValue('diffs', json_encode($data['diff']));
        $statement->bindValue('blame_id', $data['blame']['user_id']);
        $statement->bindValue('blame_user', $data['blame']['username']);
        $statement->bindValue('blame_user_fqdn', $data['blame']['user_fqdn']);
        $statement->bindValue('blame_user_firewall', $data['blame']['user_firewall']);
        $statement->bindValue('ip', $data['blame']['client_ip']);
        $statement->bindValue('created_at', $dt->format('Y-m-d H:i:s'));
        $statement->execute();
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
    private function selectStorageSpace(EntityManagerInterface $em): EntityManagerInterface
    {
        return $this->configuration->getEntityManager() ?? $em;
    }

    /**
     * @param AuditReader            $reader
     * @param EntityManagerInterface $em
     * @param string                 $hash
     * @param string                 $field
     *
     * @throws AccessDeniedException
     * @throws InvalidArgumentException
     *
     * @return null|object
     */
    public function revert(AuditReader $reader, EntityManagerInterface $em, string $hash, string $field)
    {
        $current_audit = $reader->getAuditsByTransactionHash($hash);
        // Get the fully qualified class name
        $entity_fqcn = array_keys($current_audit);
        $entity_fqcn = reset($entity_fqcn);
        /** @var AuditEntry $audited_entry */
        $audited_entry = $current_audit[$entity_fqcn];
        $audited_entry = reset($audited_entry);

        // get real entity
        $original_entity = $em->getRepository($entity_fqcn)->find($audited_entry->getObjectId());

        // Get all differences
        $diffs = $audited_entry->getDiffs();
        // get field value to revert
        $field_value = $diffs[$field]['old'];

        $field = ucfirst(strtolower($field));
        $setMethod = "set{$field}";

        $original_entity->{$setMethod}($field_value);

        return $original_entity;
    }
}
