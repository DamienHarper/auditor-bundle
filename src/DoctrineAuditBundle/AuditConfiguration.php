<?php

namespace DH\DoctrineAuditBundle;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class AuditConfiguration
{
    /**
     * @var string
     */
    private $tablePrefix;

    /**
     * @var string
     */
    private $tableSuffix;

    /**
     * @var array
     */
    private $ignoredColumns = [];

    /**
     * @var array
     */
    private $entities = [];

    /**
     * @var TokenStorage
     */
    protected $securityTokenStorage;

    /**
     * @var RequestStack
     */
    protected $requestStack;


    public function __construct(array $config, TokenStorage $securityTokenStorage, RequestStack $requestStack)
    {
        $this->securityTokenStorage = $securityTokenStorage;
        $this->requestStack = $requestStack;

        $this->tablePrefix = $config['table_prefix'];
        $this->tableSuffix = $config['table_suffix'];
        $this->ignoredColumns = $config['ignored_columns'];

        if (isset($config['entities']) && !empty($config['entities'])) {
            // use entity names as array keys for easier lookup
            foreach ($config['entities'] as $auditedEntity => $fields) {
                $this->entities[$auditedEntity] = $fields;
            }
        }
    }

    /**
     * Returns true if $entity is audited.
     *
     * @param $entity
     * @return bool
     */
    public function isAudited($entity) : bool
    {
        if (!empty($this->entities)) {
            foreach (array_keys($this->entities) as $auditedEntity) {
                if (is_object($entity) && $entity instanceof $auditedEntity) {
                    return true;
                } elseif (is_string($entity) && $entity == $auditedEntity) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Returns true if $field is audited.
     *
     * @param $entity
     * @param $field
     * @return bool
     */
    public function isAuditedField($entity, $field) : bool
    {
        if (!in_array($field, $this->ignoredColumns) && $this->isAudited($entity)) {
            $class = is_object($entity) ? get_class($entity) : $entity;
            $auditedFields = $this->entities[$class];
            return !in_array($field, $auditedFields);
        }

        return false;
    }

    /**
     * Get the value of tablePrefix.
     *
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->tablePrefix;
    }

    /**
     * Get the value of tableSuffix.
     *
     * @return string
     */
    public function getTableSuffix(): string
    {
        return $this->tableSuffix;
    }

    /**
     * Get the value of excludedColumns.
     *
     * @return string
     */
    public function getIgnoredColumns(): array
    {
        return $this->excludedColumns;
    }

    /**
     * Get the value of entities.
     *
     * @return array
     */
    public function getEntities(): array
    {
        return $this->entities;
    }

    /**
     * Get the value of securityTokenStorage.
     *
     * @return TokenStorage
     */
    public function getSecurityTokenStorage(): TokenStorage
    {
        return $this->securityTokenStorage;
    }

    /**
     * Get the value of requestStack.
     *
     * @return RequestStack
     */
    public function getRequestStack(): RequestStack
    {
        return $this->requestStack;
    }
}
