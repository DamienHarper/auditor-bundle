<?php

namespace DH\DoctrineAuditBundle\EventSubscriber;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\DBAL\AuditLogger;
use Doctrine\Common\EventSubscriber;
use Doctrine\DBAL\Logging\LoggerChain;
use Doctrine\DBAL\Logging\SQLLogger;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Security\Core\User\UserInterface;

class AuditSubscriber implements EventSubscriber
{
    private $configuration;

    /**
     * @var SQLLogger
     */
    private $loggerBackup;

    private $inserted = [];     // [$source, $changeset]
    private $updated = [];      // [$source, $changeset]
    private $removed = [];      // [$source, $id]
    private $associated = [];   // [$source, $target, $mapping]
    private $dissociated = [];  // [$source, $target, $id, $mapping]

    public function __construct(AuditConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * It is called inside EntityManager#flush() after the changes to all the managed entities
     * and their associations have been computed.
     *
     * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/reference/events.html#onflush
     *
     * @param OnFlushEventArgs $args
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getEntityManager();
        $uow = $em->getUnitOfWork();

        // extend the SQL logger
        $this->loggerBackup = $em->getConnection()->getConfiguration()->getSQLLogger();
        $loggerChain = new LoggerChain();
        $loggerChain->addLogger(new AuditLogger(function () use ($em) {
            $this->flush($em);
        }));
        if ($this->loggerBackup instanceof SQLLogger) {
            $loggerChain->addLogger($this->loggerBackup);
        }
        $em->getConnection()->getConfiguration()->setSQLLogger($loggerChain);

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $this->inserted[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $this->updated[] = [
                    $entity,
                    $uow->getEntityChangeSet($entity),
                ];
            }
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if ($this->configuration->isAudited($entity)) {
                $uow->initializeObject($entity);
                $this->removed[] = [
                    $entity,
                    $this->id($em, $entity),
                ];
            }
        }
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
                            $this->id($em, $entity),
                            $mapping,
                        ];
                    }
                }
            }
        }
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
                        $this->id($em, $entity),
                        $mapping,
                    ];
                }
            }
        }
    }

    /**
     * Flushes pending data.
     *
     * @param EntityManager $em
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function flush(EntityManager $em): void
    {
        $em->getConnection()->getConfiguration()->setSQLLogger($this->loggerBackup);
        $uow = $em->getUnitOfWork();

        foreach ($this->inserted as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($em, $entity, $ch);
        }

        foreach ($this->updated as list($entity, $ch)) {
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($em, $entity, $ch);
        }

        foreach ($this->associated as list($source, $target, $mapping)) {
            $this->associate($em, $source, $target, $mapping);
        }

        foreach ($this->dissociated as list($source, $target, $id, $mapping)) {
            $this->dissociate($em, $source, $target, $id, $mapping);
        }

        foreach ($this->removed as list($entity, $id)) {
            $this->remove($em, $entity, $id);
        }

        $this->inserted = [];
        $this->updated = [];
        $this->removed = [];
        $this->associated = [];
        $this->dissociated = [];
    }

    /**
     * Adds an insert entry to the audit table.
     *
     * @param EntityManager $em
     * @param $entity
     * @param array $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function insert(EntityManager $em, $entity, array $ch): void
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'insert',
            'blame' => $this->blame(),
            'diff' => $this->diff($em, $entity, $ch),
            'table' => $meta->table['name'],
            'id' => $this->id($em, $entity),
        ]);
    }

    /**
     * Adds an update entry to the audit table.
     *
     * @param EntityManager $em
     * @param $entity
     * @param array $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function update(EntityManager $em, $entity, array $ch): void
    {
        $diff = $this->diff($em, $entity, $ch);
        if (!$diff) {
            return; // if there is no entity diff, do not log it
        }
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'update',
            'blame' => $this->blame(),
            'diff' => $diff,
            'table' => $meta->table['name'],
            'id' => $this->id($em, $entity),
        ]);
    }

    /**
     * Adds a remove entry to the audit table.
     *
     * @param EntityManager $em
     * @param $entity
     * @param $id
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function remove(EntityManager $em, $entity, $id): void
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $this->audit($em, [
            'action' => 'remove',
            'blame' => $this->blame(),
            'diff' => $this->assoc($em, $entity),
            'table' => $meta->table['name'],
            'id' => $id,
        ]);
    }

    /**
     * Adds an association entry to the audit table.
     *
     * @param EntityManager $em
     * @param $source
     * @param $target
     * @param array $mapping
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function associate(EntityManager $em, $source, $target, array $mapping): void
    {
        $meta = $em->getClassMetadata(\get_class($source));
        $this->audit($em, [
            'action' => 'associate',
            'blame' => $this->blame(),
            'diff' => [
                'source' => $this->assoc($em, $source),
                'target' => $this->assoc($em, $target),
                'table' => isset($mapping['joinTable']['name']) ?? '',
            ],
            'table' => $meta->table['name'],
            'id' => $this->id($em, $source),
        ]);
    }

    /**
     * Adds a dissociation entry to the audit table.
     *
     * @param EntityManager $em
     * @param $source
     * @param $target
     * @param $id
     * @param array $mapping
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     */
    private function dissociate(EntityManager $em, $source, $target, $id, array $mapping): void
    {
        $meta = $em->getClassMetadata(\get_class($source));
        $this->audit($em, [
            'action' => 'dissociate',
            'blame' => $this->blame(),
            'diff' => [
                'source' => $this->assoc($em, $source),
                'target' => $this->assoc($em, $target),
                'table' => isset($mapping['joinTable']['name']) ?? '',
            ],
            'table' => $meta->table['name'],
            'id' => $id,
        ]);
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
        $auditTable = $this->configuration->getTablePrefix().$data['table'].$this->configuration->getTableSuffix();
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

        $dt = new \DateTime();
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
     * Returns the primary key value of an entity.
     *
     * @param EntityManager $em
     * @param $entity
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return mixed
     */
    private function id(EntityManager $em, $entity)
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $pk = $meta->getSingleIdentifierFieldName();
        $type = Type::getType($meta->fieldMappings[$pk]['type']);

        return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($entity));
    }

    /**
     * Computes a usable diff.
     *
     * @param EntityManager $em
     * @param $entity
     * @param array $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    private function diff(EntityManager $em, $entity, array $ch): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $diff = [];
        foreach ($ch as $fieldName => list($old, $new)) {
            if ($meta->hasField($fieldName) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $mapping = $meta->fieldMappings[$fieldName];
                $type = Type::getType($mapping['type']);
                $o = $this->value($em, $type, $old);
                $n = $this->value($em, $type, $new);
                if ($o !== $n) {
                    $diff[$fieldName] = [
                        'old' => $o,
                        'new' => $n,
                    ];
                }
            } elseif ($meta->hasAssociation($fieldName) &&
                $meta->isSingleValuedAssociation($fieldName) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $o = $this->assoc($em, $old);
                $n = $this->assoc($em, $new);
                if ($o !== $n) {
                    $diff[$fieldName] = [
                        'old' => $o,
                        'new' => $n,
                    ];
                }
            }
        }

        return $diff;
    }

    /**
     * Returns an array describing an association.
     *
     * @param EntityManager $em
     * @param null          $association
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    private function assoc(EntityManager $em, $association = null): ?array
    {
        if (null === $association) {
            return null;
        }
        $em->getUnitOfWork()->initializeObject($association); // ensure that proxies are initialized
        $meta = $em->getClassMetadata(\get_class($association));
        $pkName = $meta->getSingleIdentifierFieldName();
        $pkValue = $this->id($em, $association);
        if (method_exists($association, '__toString')) {
            $label = (string) $association;
        } else {
            $label = \get_class($association).'#'.$pkValue;
        }

        return [
            'label' => $label,
            'class' => $meta->name,
            'table' => $meta->table['name'],
            $pkName => $pkValue,
        ];
    }

    /**
     * Type converts the input value and returns it.
     *
     * @param EntityManager $em
     * @param Type          $type
     * @param $value
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return mixed
     */
    private function value(EntityManager $em, Type $type, $value)
    {
        $platform = $em->getConnection()->getDatabasePlatform();
        switch ($type->getName()) {
            case Type::TARRAY:
            case Type::SIMPLE_ARRAY:
            case Type::JSON:
            case Type::JSON_ARRAY:
                $convertedValue = $value === null ? null : $type->convertToDatabaseValue($value, $platform);
                break;

            default:
                $convertedValue = $type->convertToPHPValue($value, $platform);
        }

        return $convertedValue;
    }

    /**
     * Blames an audit operation.
     *
     * @return array
     */
    private function blame(): array
    {
        $user_id = null;
        $username = null;
        $client_ip = null;

        $request = $this->configuration->getRequestStack()->getCurrentRequest();
        if (null !== $request) {
            $client_ip = $request->getClientIp();
        }

        $token = $this->configuration->getSecurityTokenStorage()->getToken();
        if (null !== $token) {
            $user = $token->getUser();
            if ($user instanceof UserInterface) {
                $user_id = $user->getId();
                $username = $user->getUsername();
            }
        }

        return [
            'user_id' => $user_id,
            'username' => $username,
            'client_ip' => $client_ip,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents(): array
    {
        return [Events::onFlush];
    }
}
