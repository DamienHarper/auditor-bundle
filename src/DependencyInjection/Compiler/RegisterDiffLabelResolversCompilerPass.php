<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects all services tagged with "dh_auditor.diff_label_resolver" and injects a
 * PSR-11 service locator into DoctrineProvider so resolvers can be looked up at
 * write-time (during audit diff computation).
 *
 * Tag services implementing DiffLabelResolverInterface:
 *
 *   App\Audit\ProductCategoryResolver:
 *       tags: [dh_auditor.diff_label_resolver]
 *
 * Or register the interface for autoconfiguration (done automatically by the bundle):
 *
 *   App\Audit\ProductCategoryResolver implements DiffLabelResolverInterface {}
 *   // → auto-tagged, no manual tag needed
 *
 * The service ID MUST equal the resolver's FQCN, which is exactly what PHP's
 * `::class` constant produces in a #[DiffLabel(resolver: Foo::class)] attribute.
 */
final class RegisterDiffLabelResolversCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DoctrineProvider::class)) {
            return;
        }

        $taggedIds = array_keys($container->findTaggedServiceIds('dh_auditor.diff_label_resolver'));
        if ([] === $taggedIds) {
            return;
        }

        $locatorMap = [];
        foreach ($taggedIds as $serviceId) {
            $locatorMap[$serviceId] = new Reference($serviceId);
        }

        $container->getDefinition(DoctrineProvider::class)
            ->addMethodCall(
                'setDiffLabelResolverLocator',
                [new ServiceLocatorArgument($locatorMap)]
            )
        ;
    }
}
