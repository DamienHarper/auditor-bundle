<?php

namespace DH\DoctrineAuditBundle;

use DH\DoctrineAuditBundle\Helper\DoctrineHelper;
use DH\DoctrineAuditBundle\User\UserProviderInterface;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\HttpFoundation\RequestStack;

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
     * @var bool
     */
    private $enabled = true;

    /**
     * @var UserProviderInterface
     */
    protected $userProvider;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var FirewallMap
     */
    private $firewallMap;

    public function __construct(array $config, UserProviderInterface $userProvider, RequestStack $requestStack, FirewallMap $firewallMap)
    {
        $this->userProvider = $userProvider;
        $this->requestStack = $requestStack;
        $this->firewallMap = $firewallMap;

        $this->tablePrefix = $config['table_prefix'];
        $this->tableSuffix = $config['table_suffix'];
        $this->ignoredColumns = $config['ignored_columns'];

        if (isset($config['entities']) && !empty($config['entities'])) {
            // use entity names as array keys for easier lookup
            foreach ($config['entities'] as $auditedEntity => $entityOptions) {
                $this->entities[$auditedEntity] = $entityOptions;
            }
        }
    }

    /**
     * Set the value of entities.
     *
     * @param array $entities
     */
    public function setEntities(array $entities): void
    {
        $this->entities = $entities;
    }

    /**
     * enabled audit.
     *
     * @return $this
     */
    public function enable(): self
    {
        $this->enabled = true;

        return $this;
    }

    /**
     * disable audit.
     *
     * @return $this
     */
    public function disable(): self
    {
        $this->enabled = false;

        return $this;
    }

    /**
     * Get enabled flag.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Returns true if $entity is audited.
     *
     * @param object|string $entity
     *
     * @return bool
     */
    public function isAuditable($entity): bool
    {
        $class = DoctrineHelper::getRealClass($entity);

        // is $entity part of audited entities?
        if (!array_key_exists($class, $this->entities)) {
            // no => $entity is not audited
            return false;
        }

        return true;
    }

    /**
     * Returns true if $entity is audited.
     *
     * @param object|string $entity
     *
     * @return bool
     */
    public function isAudited($entity): bool
    {
        if (!$this->enabled) {
            return false;
        }

        $class = DoctrineHelper::getRealClass($entity);

        // is $entity part of audited entities?
        if (!array_key_exists($class, $this->entities)) {
            // no => $entity is not audited
            return false;
        }

        $entityOptions = $this->entities[$class];

        if (null === $entityOptions) {
            // no option defined => $entity is audited
            return true;
        }

        if (isset($entityOptions['enabled'])) {
            return (bool) $entityOptions['enabled'];
        }

        return true;
    }

    /**
     * Returns true if $field is audited.
     *
     * @param object|string $entity
     * @param string        $field
     *
     * @return bool
     */
    public function isAuditedField($entity, string $field): bool
    {
        // is $field is part of globally ignored columns?
        if (\in_array($field, $this->ignoredColumns, true)) {
            // yes => $field is not audited
            return false;
        }

        // is $entity audited?
        if (!$this->isAudited($entity)) {
            // no => $field is not audited
            return false;
        }

        $class = DoctrineHelper::getRealClass($entity);
        $entityOptions = $this->entities[$class];

        if (null === $entityOptions) {
            // no option defined => $field is audited
            return true;
        }

        // are columns excluded and is field part of them?
        if (isset($entityOptions['ignored_columns']) &&
            \in_array($field, $entityOptions['ignored_columns'], true)) {
            // yes => $field is not audited
            return false;
        }

        return true;
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
     * @return array
     */
    public function getIgnoredColumns(): array
    {
        return $this->ignoredColumns;
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
     * Enables auditing for a specific entity.
     *
     * @param string $entity Entity class name
     *
     * @return $this
     */
    public function enableAuditFor(string $entity): self
    {
        if (isset($this->entities[$entity])) {
            $this->entities[$entity]['enabled'] = true;
        }

        return $this;
    }

    /**
     * Disables auditing for a specific entity.
     *
     * @param string $entity Entity class name
     *
     * @return $this
     */
    public function disableAuditFor(string $entity): self
    {
        if (isset($this->entities[$entity])) {
            $this->entities[$entity]['enabled'] = false;
        }

        return $this;
    }

    /**
     * Get the value of userProvider.
     *
     * @return UserProviderInterface
     */
    public function getUserProvider(): ?UserProviderInterface
    {
        return $this->userProvider;
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

    /**
     * Gets the value of firewallMap.
     *
     * @return FirewallMap
     */
    public function getFirewallMap(): FirewallMap
    {
        return $this->firewallMap;
    }
}
