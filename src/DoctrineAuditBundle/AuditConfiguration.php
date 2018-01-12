<?php

namespace DH\DoctrineAuditBundle;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

class AuditConfiguration
{
    /**
     * @var string
     */
    private $table_prefix;

    /**
     * @var string
     */
    private $table_suffix;

    /**
     * @var array
     */
    private $auditedEntities = [];

    /**
     * @var array
     */
    private $unauditedEntities = [];

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
        $this->table_prefix = $config['table_prefix'];
        $this->table_suffix = $config['table_suffix'];

        if (isset($config['audited_entities']) && !empty($config['audited_entities'])) {
            // use entity names as array keys for easier lookup
            foreach ($config['audited_entities'] as $auditedEntity) {
                $this->auditedEntities[$auditedEntity] = true;
            }
        } elseif (isset($config['unaudited_entities'])) {
            // use entity names as array keys for easier lookup
            foreach ($config['unaudited_entities'] as $unauditedEntity) {
                $this->unauditedEntities[$unauditedEntity] = true;
            }
        }

        $this->securityTokenStorage = $securityTokenStorage;
        $this->requestStack = $requestStack;
    }

    /**
     * Returns true if $entity is not audited.
     *
     * @param $entity
     * @return bool
     */
    public function isUnaudited($entity) : bool
    {
        if (!empty($this->auditedEntities)) {
            // only selected entities are audited
            $isEntityUnaudited = true;
            foreach (array_keys($this->auditedEntities) as $auditedEntity) {
                if (is_object($entity) && $entity instanceof $auditedEntity) {
                    $isEntityUnaudited = false;
                    break;
                } elseif (is_string($entity) && $entity == $auditedEntity) {
                    $isEntityUnaudited = false;
                    break;
                }
            }
        } else {
            $isEntityUnaudited = false;
            foreach (array_keys($this->unauditedEntities) as $unauditedEntity) {
                if (is_object($entity) && $entity instanceof $unauditedEntity) {
                    $isEntityUnaudited = true;
                    break;
                } elseif (is_string($entity) && $entity == $unauditedEntity) {
                    $isEntityUnaudited = true;
                    break;
                }
            }
        }

        return $isEntityUnaudited;
    }

    /**
     * Returns true if $entity is audited.
     *
     * @param $entity
     * @return bool
     */
    public function isAudited($entity) : bool
    {
        return ! $this->isUnaudited($entity);
    }

    /**
     * Get the value of table_prefix.
     *
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->table_prefix;
    }

    /**
     * Get the value of table_suffix.
     *
     * @return string
     */
    public function getTableSuffix(): string
    {
        return $this->table_suffix;
    }

    /**
     * Get the value of auditedEntities.
     *
     * @return array
     */
    public function getAuditedEntities(): array
    {
        return $this->auditedEntities;
    }

    /**
     * Get the value of unauditedEntities.
     *
     * @return array
     */
    public function getUnauditedEntities(): array
    {
        return $this->unauditedEntities;
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
