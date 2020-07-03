<?php

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Configuration;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CustomConfigurationCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(Configuration::class)) {
            return;
        }

        if (!$container->hasParameter('dh_auditor.configuration')) {
            return;
        }

        $providerDefinition = $container->getDefinition(Configuration::class);
        $config = $container->getParameter('dh_auditor.configuration');

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
