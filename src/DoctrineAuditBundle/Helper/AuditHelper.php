<?php

namespace DH\DoctrineAuditBundle\Helper;

use DH\DoctrineAuditBundle\Configuration;

class AuditHelper
{
    /**
     * @var \DH\DoctrineAuditBundle\Configuration
     */
    private $configuration;

    /**
     * @param Configuration $configuration
     */
    public function __construct(Configuration $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * @return \DH\DoctrineAuditBundle\Configuration
     */
    public function getConfiguration(): Configuration
    {
        return $this->configuration;
    }

    public static function paramToNamespace(string $entity): string
    {
        return str_replace('-', '\\', $entity);
    }

    public static function namespaceToParam(string $entity): string
    {
        return str_replace('\\', '-', $entity);
    }
}
