<?php

namespace DH\AuditorBundle\DependencyInjection\Compiler;

use DH\Auditor\Provider\Doctrine\Auditing\Annotation\AnnotationLoader;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Service\AuditingService;
use DH\Auditor\Provider\Doctrine\Service\StorageService;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class DoctrineProviderConfigurationCompilerPass implements CompilerPassInterface
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

        \assert(\is_array($config) && \array_key_exists('storage_services', $config));
        foreach (array_unique($config['storage_services']) as $entityManagerName) {
            $entityManagerName = str_replace('@', '', $entityManagerName);
            $entityManagerReference = new Reference($entityManagerName);

            $service = 'dh_auditor.provider.doctrine.storage_services.'.$entityManagerName;
            $serviceDefinition = new Definition(StorageService::class, [
                $service,
                $entityManagerReference,
            ]);
            $container->setDefinition($service, $serviceDefinition);
            $serviceReference = new Reference($service);

            $providerDefinition->addMethodCall('registerStorageService', [$serviceReference]);
        }

        \assert(\is_array($config) && \array_key_exists('auditing_services', $config));
        foreach (array_unique($config['auditing_services']) as $entityManagerName) {
            $entityManagerName = str_replace('@', '', $entityManagerName);
            $entityManagerReference = new Reference($entityManagerName);

            $service = 'dh_auditor.provider.doctrine.auditing_services.'.$entityManagerName;
            $serviceDefinition = new Definition(AuditingService::class, [
                $service,
                $entityManagerReference,
            ]);
            $container->setDefinition($service, $serviceDefinition);
            $serviceReference = new Reference($service);

            $annotationLoaderDefinition = new Definition(AnnotationLoader::class, [$entityManagerReference]);
            $container->setDefinition(AnnotationLoader::class, $annotationLoaderDefinition);

            $providerDefinition->addMethodCall('registerAuditingService', [$serviceReference]);
        }
    }
}
