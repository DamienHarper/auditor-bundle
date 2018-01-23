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
use Doctrine\ORM\Mapping\ClassMetadataInfo;
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

    public function onFlush(OnFlushEventArgs $args)
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

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$this->configuration->isAudited($entity)) {
                continue;
            }
            $this->updated[] = [$entity, $uow->getEntityChangeSet($entity)];
        }
        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if (!$this->configuration->isAudited($entity)) {
                continue;
            }
            $this->inserted[] = [$entity, $ch = $uow->getEntityChangeSet($entity)];
        }
        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$this->configuration->isAudited($entity)) {
                continue;
            }
            $uow->initializeObject($entity);
            $this->removed[] = [$entity, $this->id($em, $entity)];
        }
        foreach ($uow->getScheduledCollectionUpdates() as $collection) {
            if (!$this->configuration->isAudited($collection->getOwner())) {
                continue;
            }
            $mapping = $collection->getMapping();
            if (!$mapping['isOwningSide'] || ClassMetadataInfo::MANY_TO_MANY !== $mapping['type']) {
                continue; // ignore inverse side or one to many relations
            }
            foreach ($collection->getInsertDiff() as $entity) {
                if (!$this->configuration->isAudited($entity)) {
                    continue;
                }
                $this->associated[] = [$collection->getOwner(), $entity, $mapping];
            }
            foreach ($collection->getDeleteDiff() as $entity) {
                if (!$this->configuration->isAudited($entity)) {
                    continue;
                }
                $this->dissociated[] = [$collection->getOwner(), $entity, $this->id($em, $entity), $mapping];
            }
        }
        foreach ($uow->getScheduledCollectionDeletions() as $collection) {
            if (!$this->configuration->isAudited($collection->getOwner())) {
                continue;
            }
            $mapping = $collection->getMapping();
            if (!$mapping['isOwningSide'] || ClassMetadataInfo::MANY_TO_MANY !== $mapping['type']) {
                continue; // ignore inverse side or one to many relations
            }
            foreach ($collection->toArray() as $entity) {
                if (!$this->configuration->isAudited($entity)) {
                    continue;
                }
                $this->dissociated[] = [$collection->getOwner(), $entity, $this->id($em, $entity), $mapping];
            }
        }
    }

    private function flush(EntityManager $em)
    {
        $em->getConnection()->getConfiguration()->setSQLLogger($this->loggerBackup);
        $uow = $em->getUnitOfWork();

        foreach ($this->updated as $entry) {
            list($entity, $ch) = $entry;
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->update($em, $entity, $ch);
        }

        foreach ($this->inserted as $entry) {
            list($entity, $ch) = $entry;
            // the changeset might be updated from UOW extra updates
            $ch = array_merge($ch, $uow->getEntityChangeSet($entity));
            $this->insert($em, $entity, $ch);
        }

        foreach ($this->associated as $entry) {
            list($source, $target, $mapping) = $entry;
            $this->associate($em, $source, $target, $mapping);
        }

        foreach ($this->dissociated as $entry) {
            list($source, $target, $id, $mapping) = $entry;
            $this->dissociate($em, $source, $target, $id, $mapping);
        }

        foreach ($this->removed as $entry) {
            list($entity, $id) = $entry;
            $this->remove($em, $entity, $id);
        }

        $this->inserted = [];
        $this->updated = [];
        $this->removed = [];
        $this->associated = [];
        $this->dissociated = [];
    }

    private function insert(EntityManager $em, $entity, array $ch)
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $this->audit($em, [
            'action'    => 'insert',
            'blame'     => $this->blame(),
            'diff'      => $this->diff($em, $entity, $ch),
            'table'     => $meta->table['name'],
            'id'        => $this->id($em, $entity),
        ]);
    }

    private function update(EntityManager $em, $entity, array $ch)
    {
        $diff = $this->diff($em, $entity, $ch);
        if (!$diff) {
            return; // if there is no entity diff, do not log it
        }
        $meta = $em->getClassMetadata(get_class($entity));
        $this->audit($em, [
            'action'    => 'update',
            'blame'     => $this->blame(),
            'diff'      => $diff,
            'table'     => $meta->table['name'],
            'id'        => $this->id($em, $entity),
        ]);
    }

    private function remove(EntityManager $em, $entity, $id)
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $this->audit($em, [
            'action'    => 'remove',
            'blame'     => $this->blame(),
            'diff'      => $this->assoc($em, $entity),
            'table'     => $meta->table['name'],
            'id'        => $id,
        ]);
    }

    private function associate(EntityManager $em, $source, $target, array $mapping)
    {
        $meta = $em->getClassMetadata(get_class($source));
        $this->audit($em, [
            'action' => 'associate',
            'blame' => $this->blame(),
            'diff' => [
                'source' => $this->assoc($em, $source),
                'target' => $this->assoc($em, $target),
                'table' => $mapping['joinTable']['name'],
            ],
            'table'     => $meta->table['name'],
            'id'        => $this->id($em, $source),
        ]);
    }

    private function dissociate(EntityManager $em, $source, $target, $id, array $mapping)
    {
        $meta = $em->getClassMetadata(get_class($source));
        $this->audit($em, [
            'action' => 'dissociate',
            'blame' => $this->blame(),
            'diff' => [
                'source' => $this->assoc($em, $source),
                'target' => $this->assoc($em, $target),
                'table' => $mapping['joinTable']['name'],
            ],
            'table'     => $meta->table['name'],
            'id'        => $id,
        ]);
    }

    private function audit(EntityManager $em, array $data)
    {
        $auditTable = $this->configuration->getTablePrefix().$data['table'].$this->configuration->getTableSuffix();
        $fields = [
            'type'          => ':type',
            'object_id'     => ':object_id',
            'diffs'         => ':diffs',
            'blame_id'      => ':blame_id',
            'blame_user'    => ':blame_user',
            'ip'            => ':ip',
            'created_at'    => ':created_at',
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

    private function id(EntityManager $em, $entity)
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $pk = $meta->getSingleIdentifierFieldName();
        $pk = $this->value(
            $em,
            Type::getType($meta->fieldMappings[$pk]['type']),
            $meta->getReflectionProperty($pk)->getValue($entity)
        );

        return $pk;
    }

    private function diff(EntityManager $em, $entity, array $ch)
    {
        $meta = $em->getClassMetadata(get_class($entity));
        $diff = [];
        foreach ($ch as $fieldName => list($old, $new)) {
            if ($meta->hasField($fieldName) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $mapping = $meta->fieldMappings[$fieldName];
                $diff[$fieldName] = [
                    '-' => $this->value($em, Type::getType($mapping['type']), $old),
                    '+' => $this->value($em, Type::getType($mapping['type']), $new),
                ];
                if ($diff[$fieldName]['-'] == $diff[$fieldName]['+']) {
                    unset($diff[$fieldName]);
                }
            } elseif ($meta->hasAssociation($fieldName) && $meta->isSingleValuedAssociation($fieldName) && $this->configuration->isAuditedField($entity, $fieldName)) {
                $diff[$fieldName] = [
                    '-' => $this->assoc($em, $old),
                    '+' => $this->assoc($em, $new),
                ];
                if ($diff[$fieldName]['-'] == $diff[$fieldName]['+']) {
                    unset($diff[$fieldName]);
                }
            }
        }

        return $diff;
    }

    private function assoc(EntityManager $em, $association = null)
    {
        if (null === $association) {
            return null;
        }
        $em->getUnitOfWork()->initializeObject($association); // ensure that proxies are initialized
        $meta = $em->getClassMetadata(get_class($association));
        $pkName = $meta->getSingleIdentifierFieldName();
        $pkValue = $this->id($em, $association);
        if (method_exists($association, '__toString')) {
            $label = (string) $association;
        } else {
            $label = get_class($association) . '#' . $pkValue;
        }

        return [
            'label' => $label,
            'class' => $meta->name,
            'table' => $meta->table['name'],
            $pkName => $pkValue,
        ];
    }

    private function value(EntityManager $em, Type $type, $value)
    {
        $platform = $em->getConnection()->getDatabasePlatform();
        switch ($type->getName()) {
            case Type::BOOLEAN:
                return $type->convertToPHPValue($value, $platform); // json supports boolean values
            default:
                return $type->convertToDatabaseValue($value, $platform);
        }
    }

    private function blame()
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

        $data = [
            'user_id' => $user_id,
            'username' => $username,
            'client_ip' => $client_ip,
        ];

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return [Events::onFlush];
    }
}
