<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Auditor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers all services tagged with "dh_auditor.provider" into the Auditor service.
 *
 * This allows custom providers to be wired automatically without any manual
 * registration in services.yaml beyond adding the tag:
 *
 *   App\Audit\MyCustomProvider:
 *       tags: [dh_auditor.provider]
 *
 * @see Tests\DependencyInjection\Compiler\RegisterProvidersCompilerPassTest
 */
final class RegisterProvidersCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Auditor::class)) {
            return;
        }

        $auditorDefinition = $container->getDefinition(Auditor::class);

        foreach (array_keys($container->findTaggedServiceIds('dh_auditor.provider')) as $serviceId) {
            $auditorDefinition->addMethodCall('registerProvider', [new Reference($serviceId)]);

            // Inject Auditor directly into the provider at instantiation time.
            // This guarantees that the provider's Auditor reference is set as soon as the
            // provider is constructed — which may happen *before* the Auditor service is first
            // requested (e.g. when DoctrineSubscriber fires a Doctrine event at startup).
            //
            // Note: Auditor::registerProvider() also calls setAuditor() internally, so this
            // results in setAuditor() being called twice. This is intentional and idempotent —
            // it ensures correctness regardless of which service is instantiated first.
            if ($container->hasDefinition($serviceId)) {
                $container->getDefinition($serviceId)->addMethodCall('setAuditor', [new Reference(Auditor::class)]);
            }
        }
    }
}
