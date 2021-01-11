<?php

namespace DH\AuditorBundle;

use DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\CustomConfigurationCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineProviderConfigurationCompilerPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class DHAuditorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddProviderCompilerPass());
        $container->addCompilerPass(new DoctrineProviderConfigurationCompilerPass());
        $container->addCompilerPass(new CustomConfigurationCompilerPass());
    }
}
