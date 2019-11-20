<?php

namespace DH\DoctrineAuditBundle;

use DH\DoctrineAuditBundle\DependencyInjection\Compiler\StorageConfigurationCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DHDoctrineAuditBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new StorageConfigurationCompilerPass());
    }
}
