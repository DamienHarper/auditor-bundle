<?php

declare(strict_types=1);

namespace DH\AuditorBundle\DependencyInjection;

use DH\Auditor\Provider\ProviderInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader;

class DHAuditorExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yaml');

        $auditorConfig = $config;
        unset($auditorConfig['providers']);
        $container->setParameter('dh_auditor.configuration', $auditorConfig);

        $this->loadProviders($container, $config);
    }

    private function loadProviders(ContainerBuilder $container, array $config): void
    {
        foreach ($config['providers'] as $providerName => $providerConfig) {
            $container->setParameter('dh_auditor.provider.'.$providerName.'.configuration', $providerConfig);

            $container->registerAliasForArgument('dh_auditor.provider.'.$providerName, ProviderInterface::class, \sprintf('%sProvider', $providerName));
        }
    }
}
