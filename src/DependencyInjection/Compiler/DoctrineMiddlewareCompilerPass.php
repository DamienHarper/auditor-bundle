<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\Auditing\DBAL\Middleware\AuditorMiddleware;
use Doctrine\DBAL\Driver\Middleware;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * This CompilerPass configures the Doctrine DBAL middleware for auditing.
 * It must run after Doctrine services are defined.
 */
class DoctrineMiddlewareCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!interface_exists(Middleware::class) || !class_exists(AuditorMiddleware::class)) {
            return;
        }

        $doctrineProviderConfigurationKey = 'dh_auditor.provider.doctrine.configuration';
        if (!$container->hasParameter($doctrineProviderConfigurationKey)) {
            return;
        }

        $config = $container->getParameter($doctrineProviderConfigurationKey);
        \assert(\is_array($config) && \array_key_exists('auditing_services', $config));

        // Register the base middleware service if not already registered
        if (!$container->hasDefinition('doctrine.dbal.auditor_middleware')) {
            $container->register('doctrine.dbal.auditor_middleware', AuditorMiddleware::class);
        }

        /** @var list<string> $auditingServices */
        $auditingServices = $config['auditing_services'];
        foreach (array_unique($auditingServices) as $entityManagerName) {
            $this->configureAuditorMiddleware($container, str_replace('@', '', $entityManagerName));
        }
    }

    private function configureAuditorMiddleware(ContainerBuilder $container, string $entityManagerName): void
    {
        if (!$container->hasDefinition($entityManagerName)) {
            return;
        }

        $argument = $container->getDefinition($entityManagerName)->getArgument(0);
        if (!$argument instanceof Reference) {
            return;
        }

        $connectionName = (string) $argument;
        $configurationName = $connectionName.'.configuration';

        if (!$container->hasDefinition($configurationName)) {
            return;
        }

        $container
            ->setDefinition(
                $connectionName.'.auditor_middleware',
                new ChildDefinition('doctrine.dbal.auditor_middleware')
            )
            ->addTag('doctrine.middleware')
        ;
    }
}
