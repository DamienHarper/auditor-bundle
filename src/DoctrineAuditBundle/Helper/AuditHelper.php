<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\User\UserInterface;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;

class AuditHelper
{
    /**
     * @var \DH\DoctrineAuditBundle\AuditConfiguration
     */
    private $configuration;

    /**
     * @param AuditConfiguration $configuration
     */
    public function __construct(AuditConfiguration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return \DH\DoctrineAuditBundle\AuditConfiguration
     */
    public function getConfiguration(): AuditConfiguration
    {
        return $this->configuration;
    }

    /**
     * Returns the primary key value of an entity.
     *
     * @param EntityManager $em
     * @param object        $entity
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return mixed
     */
    public function id(EntityManager $em, $entity)
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $pk = $meta->getSingleIdentifierFieldName();

        if (!isset($meta->fieldMappings[$pk])) {
            // Primary key is not part of fieldMapping
            // @see https://github.com/DamienHarper/DoctrineAuditBundle/issues/40
            // @see https://www.doctrine-project.org/projects/doctrine-orm/en/latest/tutorials/composite-primary-keys.html#identity-through-foreign-entities
            // We try to get it from associationMapping (will throw a MappingException if not available)
            $targetEntity = $meta->getReflectionProperty($pk)->getValue($entity);

            $mapping = $meta->getAssociationMapping($pk);
            $meta = $em->getClassMetadata($mapping['targetEntity']);
            $pk = $meta->getSingleIdentifierFieldName();
            $type = Type::getType($meta->fieldMappings[$pk]['type']);

            return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($targetEntity));
        }

        $type = Type::getType($meta->fieldMappings[$pk]['type']);

        return $this->value($em, $type, $meta->getReflectionProperty($pk)->getValue($entity));
    }

    /**
     * Computes a usable diff.
     *
     * @param EntityManager $em
     * @param object        $entity
     * @param array         $ch
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    public function diff(EntityManager $em, $entity, array $ch): array
    {
        $meta = $em->getClassMetadata(\get_class($entity));
        $diff = [];
        foreach ($ch as $fieldName => list($old, $new)) {
            if ($meta->hasField($fieldName) && !isset($meta->embeddedClasses[$fieldName]) &&
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
     * Type converts the input value and returns it.
     *
     * @param EntityManager $em
     * @param Type          $type
     * @param mixed         $value
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @return mixed
     */
    private function value(EntityManager $em, Type $type, $value)
    {
        if (null === $value) {
            return null;
        }

        $platform = $em->getConnection()->getDatabasePlatform();

        switch ($type->getName()) {
            case Type::DECIMAL:
            case Type::BIGINT:
                $convertedValue = (string) $value;

                break;
            case Type::INTEGER:
            case Type::SMALLINT:
                $convertedValue = (int) $value;

                break;
            case Type::FLOAT:
            case Type::BOOLEAN:
                $convertedValue = $type->convertToPHPValue($value, $platform);

                break;
            default:
                $convertedValue = $type->convertToDatabaseValue($value, $platform);
        }

        return $convertedValue;
    }

    /**
     * Blames an audit operation.
     *
     * @return array
     */
    public function blame(): array
    {
        $user_id = null;
        $username = null;
        $client_ip = null;

        $request = $this->configuration->getRequestStack()->getCurrentRequest();
        if (null !== $request) {
            $client_ip = $request->getClientIp();
        }

        $user = $this->configuration->getUserProvider()->getUser();
        if ($user instanceof UserInterface) {
            $user_id = $user->getId();
            $username = $user->getUsername();
        }

        return [
            'user_id' => $user_id,
            'username' => $username,
            'client_ip' => $client_ip,
        ];
    }

    /**
     * Returns an array describing an association.
     *
     * @param EntityManager $em
     * @param object        $association
     * @param mixed         $id
     *
     * @throws \Doctrine\DBAL\DBALException
     * @throws \Doctrine\ORM\Mapping\MappingException
     *
     * @return array
     */
    public function assoc(EntityManager $em, $association = null, $id = null): ?array
    {
        if (null === $association) {
            return null;
        }

        $em->getUnitOfWork()->initializeObject($association); // ensure that proxies are initialized
        $meta = $em->getClassMetadata(\get_class($association));
        $pkName = $meta->getSingleIdentifierFieldName();
        $pkValue = $id ?? $this->id($em, $association);
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
}
