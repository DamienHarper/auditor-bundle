<?php

namespace DH\DoctrineAuditBundle;

use DH\DoctrineAuditBundle\Helper\AuditHelper;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;

class AuditManager
{
    /**
     * @var \DH\DoctrineAuditBundle\AuditConfiguration
     */
    private $configuration;

    private $inserted = [];     // [$source, $changeset]
    private $updated = [];      // [$source, $changeset]
    private $removed = [];      // [$source, $id]
    private $associated = [];   // [$source, $target, $mapping]
    private $dissociated = [];  // [$source, $target, $id, $mapping]

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
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
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
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
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
            'diff' => $this->helper->summarize($em, $entity, $id),
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
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
     * @param EntityManager $em
     * @param object        $entity
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function softDelete(EntityManager $em, $entity): void
    {
        if ($this->configuration->isAudited($entity)) {
            $this->removed[] = [
                $entity,
                $this->helper->id($em, $entity),
            ];
        }
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
                'source' => $this->helper->summarize($em, $source),
                'target' => $this->helper->summarize($em, $target),
            ],
            'table' => $meta->getTableName(),
            'schema' => $meta->getSchemaName(),
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
     * @param \Doctrine\ORM\UnitOfWork $uow
     */
    public function collectScheduledInsertions(\Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $this->inserted[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     */
    public function collectScheduledUpdates(\Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $this->updated[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     * @param EntityManager            $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function collectScheduledDeletions(\Doctrine\ORM\UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $uow->initializeObject($entity);
                $this->removed[] = [
                    $entity,
                    $this->helper->id($em, $entity),
                ];
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     * @param EntityManager            $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function collectScheduledCollectionUpdates(\Doctrine\ORM\UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->getInsertDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $this->associated[] = [
                            $collection->getOwner(),
                            $entity,
                            $mapping,
                        ];
                    }
                }
                foreach ($collection->getDeleteDiff() as $entity) {
                    if ($this->configuration->isAudited($entity)) {
                        $this->dissociated[] = [
                            $collection->getOwner(),
                            $entity,
                            $this->helper->id($em, $entity),
                            $mapping,
                        ];
                    }
                }
            }
        }
    }

    /**
     * @param \Doctrine\ORM\UnitOfWork $uow
     * @param EntityManager            $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function collectScheduledCollectionDeletions(\Doctrine\ORM\UnitOfWork $uow, EntityManager $em): void
    {
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if ($this->configuration->isAudited($collection->getOwner())) {
                $mapping = $collection->getMapping();
                foreach ($collection->toArray() as $entity) {
                    if (!$this->configuration->isAudited($entity)) {
                        continue;
                    }
                    $this->dissociated[] = [
                        $collection->getOwner(),
                        $entity,
                        $this->helper->id($em, $entity),
                        $mapping,
                    ];
                }
            }
        }
    }

    /**
     * @param EntityManager            $em
     * @param \Doctrine\ORM\UnitOfWork $uow
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processInsertions(EntityManager $em, \Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($this->inserted as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($em, $entity, $ch);
        }
    }

    /**
     * @param EntityManager            $em
     * @param \Doctrine\ORM\UnitOfWork $uow
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processUpdates(EntityManager $em, \Doctrine\ORM\UnitOfWork $uow): void
    {
        foreach ($this->updated as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($em, $entity, $ch);
        }
    }

    /**
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processAssociations(EntityManager $em): void
    {
        foreach ($this->associated as list($source, $target, $mapping)) {
            $this->associate($em, $source, $target, $mapping);
        }
    }

    /**
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processDissociations(EntityManager $em): void
    {
        foreach ($this->dissociated as list($source, $target, $id, $mapping)) {
            $this->dissociate($em, $source, $target, $mapping);
        }
    }

    /**
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function processDeletions(EntityManager $em): void
    {
        foreach ($this->removed as list($entity, $id)) {
            $this->remove($em, $entity, $id);
        }
    }

    public function reset(): void
    {
        $this->inserted = [];
        $this->updated = [];
        $this->removed = [];
        $this->associated = [];
        $this->dissociated = [];
    }

    /**
     * @param EntityManagerInterface $em
     *
     * @return EntityManagerInterface
     */
    private function selectStorageSpace(EntityManagerInterface $em): EntityManagerInterface
    {
        return $this->configuration->getCustomStorageEntityManager() ?? $em;
    }
}
