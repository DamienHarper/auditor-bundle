<?php

namespace DH\DoctrineAuditBundle\DependencyInjection\Compiler;

use DH\DoctrineAuditBundle\AuditConfiguration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class StorageConfigurationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dh_doctrine_audit.configuration')) {
            return;
        }

        $config = $container->getParameter('dh_doctrine_audit.configuration');

        if (isset($config['storage_entity_manager'])) {
            $auditConfiguration = $container->get('dh_doctrine_audit.configuration');

            if (null !== $auditConfiguration) {
                $auditConfiguration = new AuditConfiguration(
                    $config,
                    $auditConfiguration->getUserProvider(),
                    $auditConfiguration->getRequestStack(),
                    $auditConfiguration->getFirewallMap(),
                    $container->get($config['storage_entity_manager']),
                    $auditConfiguration->getAnnotationLoader()
                );

                $container->set('dh_doctrine_audit.configuration', $auditConfiguration);
            }
        }
    }
}
