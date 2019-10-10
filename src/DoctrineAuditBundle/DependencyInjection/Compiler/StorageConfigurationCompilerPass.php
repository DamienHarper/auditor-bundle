<?php

namespace DH\DoctrineAuditBundle\DependencyInjection\Compiler;

use DH\DoctrineAuditBundle\Exception\InvalidArgumentException;
use Doctrine\ORM\EntityManagerInterface;
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
        if (null === $config['storage_entity_manager']) {
            return;
        }

        $entityManager = $container->get($config['storage_entity_manager']);
        if (!($entityManager instanceof EntityManagerInterface)) {
            throw new InvalidArgumentException(sprintf('Service "%s" must implement "%s".', $config['storage_entity_manager'], EntityManagerInterface::class));
        }

        $definition = $container->getDefinition('dh_doctrine_audit.configuration');
        $definition->replaceArgument(4, $entityManager);
    }
}
