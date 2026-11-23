<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\AuditorBundle\Attribute\AsChangeDescriber;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\Tests\DependencyInjection\Compiler\RegisterChangeDescribersCompilerPassTest;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives HistoryPhraser a locator of the change describers (services tagged "dh_auditor.change_describer", which
 * #[AsChangeDescriber] does), keyed "FQCN::field" for a field and "FQCN" for an entity.
 *
 * @see AsChangeDescriber
 * @see RegisterChangeDescribersCompilerPassTest
 */
final class RegisterChangeDescribersCompilerPass implements CompilerPassInterface
{
    public const string TAG = 'dh_auditor.change_describer';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(HistoryPhraser::class)) {
            return;
        }

        $describers = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            foreach ($tags as $attributes) {
                $attributes = \is_array($attributes) ? $attributes : [];
                $entity = $attributes['entity'] ?? null;
                if (!\is_string($entity) || '' === $entity) {
                    throw new InvalidArgumentException(\sprintf('The "%s" tag of service "%s" needs an "entity" attribute.', self::TAG, $id));
                }

                $field = $attributes['field'] ?? null;
                $key = \is_string($field) && '' !== $field ? $entity.'::'.$field : $entity;
                if (isset($describers[$key])) {
                    throw new InvalidArgumentException(\sprintf('The services "%s" and "%s" both describe the changes of %s.', $describers[$key], $id, $key));
                }

                $describers[$key] = $id;
            }
        }

        ksort($describers);

        $container->getDefinition(HistoryPhraser::class)->setArgument(
            '$describers',
            ServiceLocatorTagPass::register($container, array_map(static fn (string $id): Reference => new Reference($id), $describers)),
        );
    }
}
