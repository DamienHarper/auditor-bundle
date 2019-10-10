<?php

namespace DH\DoctrineAuditBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class StorageConfigurationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dh_doctrine_audit.configuration')) {
            return;
        }

        $config = $container->getParameter('dh_doctrine_audit.configuration');
        if (null === $config['storage_entity_manager']) {
            return;
        }

        $reference = new Reference($config['storage_entity_manager']);

        $definition = $container->getDefinition('dh_doctrine_audit.configuration');
        $definition->replaceArgument(4, $reference);
    }
}
