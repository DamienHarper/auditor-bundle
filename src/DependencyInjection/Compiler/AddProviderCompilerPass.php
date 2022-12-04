<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Auditor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class AddProviderCompilerPass implements CompilerPassInterface
{
    /**
     * Get all providers based on their tag (`dh_auditor.provider`) and register them.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Auditor::class)) {
            return;
        }

        $auditorDefinition = $container->getDefinition(Auditor::class);

        $providers = [];
        foreach (array_keys($container->findTaggedServiceIds('dh_auditor.provider')) as $providerId) {
            $providers[] = new Reference($providerId);
        }

        foreach ($providers as $provider) {
            $auditorDefinition->addMethodCall('registerProvider', [$provider]);
        }
    }
}
