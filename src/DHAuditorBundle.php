<?php

declare(strict_types=1);

namespace DH\AuditorBundle;

use DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\CustomConfigurationCompilerPass;
use DH\AuditorBundle\DependencyInjection\Compiler\DoctrineProviderConfigurationCompilerPass;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * @see \DH\AuditorBundle\Tests\DHAuditorBundleTest
 */
class DHAuditorBundle extends Bundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new AddProviderCompilerPass());
        $container->addCompilerPass(new DoctrineProviderConfigurationCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 1);
        $container->addCompilerPass(new CustomConfigurationCompilerPass());
    }
}
