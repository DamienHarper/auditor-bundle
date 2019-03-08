<?php

namespace DH\DoctrineAuditBundle;

use DH\DoctrineAuditBundle\Helper\AuditHelper;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;

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
    public function getAuditConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Adds an insert entry to the audit table.
     *
     * @param EntityManager $em
     * @param object        $entity
     * @param array         $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function insert(EntityManager $em, $entity, array $ch): void
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'insert',
            'blame' => $this->helper->blame(),
            'diff' => $this->helper->diff($em, $entity, $ch),
            'table' => $meta->table['name'],
            'schema' => $meta->table['schema'] ?? null,
            'id' => $this->helper->id($em, $entity),
        ]);
    }

    /**
     * Adds an update entry to the audit table.
     *
     * @param EntityManager $em
     * @param object        $entity
     * @param array         $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function update(EntityManager $em, $entity, array $ch): void
    {
        $diff = $this->helper->diff($em, $entity, $ch);
        if (!$diff) {
            return; // if there is no entity diff, do not log it
        }
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'update',
            'blame' => $this->helper->blame(),
            'diff' => $diff,
            'table' => $meta->table['name'],
            'schema' => $meta->table['schema'] ?? null,
            'id' => $this->helper->id($em, $entity),
        ]);
    }

    /**
     * Adds a remove entry to the audit table.
     *
     * @param EntityManager $em
     * @param object        $entity
     * @param mixed         $id
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function remove(EntityManager $em, $entity, $id): void
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'remove',
            'blame' => $this->helper->blame(),
            'diff' => $this->helper->assoc($em, $entity, $id),
            'table' => $meta->table['name'],
            'schema' => $meta->table['schema'] ?? null,
            'id' => $id,
        ]);
    }

    /**
     * Adds an association entry to the audit table.
     *
     * @param EntityManager $em
     * @param object        $source
     * @param object        $target
     * @param array         $mapping
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function associate(EntityManager $em, $source, $target, array $mapping): void
    {
        $this->associateOrDissociate('associate', $em, $source, $target, $mapping);
    }

    /**
     * Adds a dissociation entry to the audit table.
     *
     * @param EntityManager $em
     * @param object        $source
     * @param object        $target
     * @param array         $mapping
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function dissociate(EntityManager $em, $source, $target, array $mapping): void
    {
        $this->associateOrDissociate('dissociate', $em, $source, $target, $mapping);
    }

    /**
     * Adds an association entry to the audit table.
     *
     * @param string        $type
     * @param EntityManager $em
     * @param object        $source
     * @param object        $target
     * @param array         $mapping
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function associateOrDissociate(string $type, EntityManager $em, $source, $target, array $mapping): void
    {
        $meta = $em->getClassMetadata(\get_class($source));
        $data = [
            'action' => $type,
            'blame' => $this->helper->blame(),
            'diff' => [
                'source' => $this->helper->assoc($em, $source),
                'target' => $this->helper->assoc($em, $target),
            ],
            'table' => $meta->table['name'],
            'schema' => $meta->table['schema'] ?? null,
            'id' => $this->helper->id($em, $source),
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
            'diffs' => ':diffs',
            'blame_id' => ':blame_id',
            'blame_user' => ':blame_user',
            'ip' => ':ip',
            'created_at' => ':created_at',
        ];

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $auditTable,
            implode(', ', array_keys($fields)),
            implode(', ', array_values($fields))
        );

        $statement = $em->getConnection()->prepare($query);

        $dt = new \DateTime('now', new \DateTimeZone('UTC'));
        $statement->bindValue('type', $data['action']);
        $statement->bindValue('object_id', $data['id']);
        $statement->bindValue('diffs', json_encode($data['diff']));
        $statement->bindValue('blame_id', $data['blame']['user_id']);
        $statement->bindValue('blame_user', $data['blame']['username']);
        $statement->bindValue('ip', $data['blame']['client_ip']);
        $statement->bindValue('created_at', $dt->format('Y-m-d H:i:s'));
        $statement->execute();
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
     * Set the value of helper.
     *
     * @param AuditHelper $helper
     *
     * @return AuditHelper
     */
    public function setHelper(AuditHelper $helper): self
    {
        $this->helper = $helper;

        return $this;
    }
}
