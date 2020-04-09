<?php

namespace DH\DoctrineAuditBundle\Manager;

use DateTime;
use DateTimeZone;
use DH\DoctrineAuditBundle\Configuration;
use DH\DoctrineAuditBundle\Event\LifecycleEvent;
use DH\DoctrineAuditBundle\Helper\DoctrineHelper;
use DH\DoctrineAuditBundle\Model\Transaction;
use DH\DoctrineAuditBundle\User\UserInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Exception;

class TransactionManager
{
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
     * @return Configuration
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
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
     * @param EntityManagerInterface $em
     *
     * @return EntityManagerInterface
     */
    public function selectStorageSpace(EntityManagerInterface $em): EntityManagerInterface
    {
        return $this->configuration->getEntityManager() ?? $em;
    }

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
     * Returns the primary key value of an entity.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return mixed
     */
    private function id(EntityManagerInterface $em, $entity)
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $pk = $meta->getSingleIdentifierFieldName();

        if (isset($meta->fieldMappings[$pk])) {
            $type = Type::getType($meta->fieldMappings[$pk]['type']);

            return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($entity));
        }

        /**
         * Primary key is not part of fieldMapping.
         *
         * @see https://github.com/DamienHarper/DoctrineAuditBundle/issues/40
         * @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html#identity-through-foreign-entities
         * We try to get it from associationMapping (will throw a MappingException if not available)
         */
        $targetEntity = $meta->getReflectionProperty($pk)->getValue($entity);

        $mapping = $meta->getAssociationMapping($pk);

        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata($mapping['targetEntity']);
        $pk = $meta->getSingleIdentifierFieldName();
        $type = Type::getType($meta->fieldMappings[$pk]['type']);

        return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($targetEntity));
    }

    /**
     * Computes a usable diff.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param array                  $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    private function diff(EntityManagerInterface $em, $entity, array $ch): array
    {
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $diff = [];

        foreach ($ch as $fieldName => list($old, $new)) {
            $o = null;
            $n = null;

            if (
                $meta->hasField($fieldName) &&
                !isset($meta->embeddedClasses[$fieldName]) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $mapping = $meta->fieldMappings[$fieldName];
                $type = Type::getType($mapping['type']);
                $o = $this->value($em, $type, $old);
                $n = $this->value($em, $type, $new);
            } elseif (
                $meta->hasAssociation($fieldName) &&
                $meta->isSingleValuedAssociation($fieldName) &&
                $this->configuration->isAuditedField($entity, $fieldName)
            ) {
                $o = $this->summarize($em, $old);
                $n = $this->summarize($em, $new);
            }

            if ($o !== $n) {
                $diff[$fieldName] = [
                    'old' => $o,
                    'new' => $n,
                ];
            }
        }
        ksort($diff);

        return $diff;
    }

    /**
     * Returns an array describing an entity.
     *
     * @param EntityManagerInterface $em
     * @param object                 $entity
     * @param mixed                  $id
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    private function summarize(EntityManagerInterface $em, $entity = null, $id = null): ?array
    {
        if (null === $entity) {
            return null;
        }

        $em->getUnitOfWork()->initializeObject($entity); // ensure that proxies are initialized
        /** @var ClassMetadata $meta */
        $meta = $em->getClassMetadata(DoctrineHelper::getRealClassName($entity));
        $pkName = $meta->getSingleIdentifierFieldName();
        $pkValue = $id ?? $this->id($em, $entity);
        // An added guard for proxies that fail to initialize.
        if (null === $pkValue) {
            return null;
        }

        if (method_exists($entity, '__toString')) {
            $label = (string) $entity;
        } else {
            $label = DoctrineHelper::getRealClassName($entity).'#'.$pkValue;
        }

        return [
            'label' => $label,
            'class' => $meta->name,
            'table' => $meta->getTableName(),
            $pkName => $pkValue,
        ];
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
        $user_fqdn = null;
        $user_firewall = null;

        $request = $this->configuration->getRequestStack()->getCurrentRequest();
        if (null !== $request) {
            $client_ip = $request->getClientIp();
            $user_firewall = null === $this->configuration->getFirewallMap()->getFirewallConfig($request) ? null : $this->configuration->getFirewallMap()->getFirewallConfig($request)->getName();
        }

        $user = null === $this->configuration->getUserProvider() ? null : $this->configuration->getUserProvider()->getUser();
        if ($user instanceof UserInterface) {
            $user_id = $user->getId();
            $username = $user->getUsername();
            $user_fqdn = DoctrineHelper::getRealClassName($user);
        }

        return [
            'user_id' => $user_id,
            'username' => $username,
            'client_ip' => $client_ip,
            'user_fqdn' => $user_fqdn,
            'user_firewall' => $user_firewall,
        ];
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
                    $this->id($em, $entity),
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
                            $this->id($em, $entity),
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
                            $this->id($em, $entity),
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

    /**
     * Type converts the input value and returns it.
     *
     * @param EntityManagerInterface $em
     * @param Type                   $type
     * @param mixed                  $value
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return mixed
     */
    private function value(EntityManagerInterface $em, Type $type, $value)
    {
        if (null === $value) {
            return null;
        }

        $platform = $em->getConnection()->getDatabasePlatform();

        switch ($type->getName()) {
            case DoctrineHelper::getDoctrineType('BIGINT'):
                $convertedValue = (string) $value;

                break;
            case DoctrineHelper::getDoctrineType('INTEGER'):
            case DoctrineHelper::getDoctrineType('SMALLINT'):
                $convertedValue = (int) $value;

                break;
            case DoctrineHelper::getDoctrineType('DECIMAL'):
            case DoctrineHelper::getDoctrineType('FLOAT'):
            case DoctrineHelper::getDoctrineType('BOOLEAN'):
                $convertedValue = $type->convertToPHPValue($value, $platform);

                break;
            default:
                $convertedValue = $type->convertToDatabaseValue($value, $platform);
        }

        return $convertedValue;
    }
}
