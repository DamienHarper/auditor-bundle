<?php

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CustomConfigurationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(DoctrineProvider::class)) {
            return;
        }

        $doctrineProviderConfigurationKey = 'dh_auditor.provider.doctrine.configuration';
        if (!$container->hasParameter($doctrineProviderConfigurationKey)) {
            return;
        }

        $providerDefinition = $container->getDefinition(DoctrineProvider::class);
        $config = $container->getParameter($doctrineProviderConfigurationKey);

        // User provider service
        $serviceId = $config['user_provider'];
        if (null !== $serviceId) {
            $reference = new Reference($serviceId);
            $providerDefinition->addMethodCall('setUserProvider', [$reference]);
        }

        // Role checker service
        $serviceId = $config['role_checker'];
        if (null !== $serviceId) {
            $reference = new Reference($serviceId);
            $providerDefinition->addMethodCall('setRoleChecker', [$reference]);
        }

        // Security service
        $serviceId = $config['security_provider'];
        if (null !== $serviceId) {
            $reference = new Reference($serviceId);
            $providerDefinition->addMethodCall('setSecurityProvider', [$reference]);
        }
    }
}
