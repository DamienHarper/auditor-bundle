<?php

declare(strict_types=1);

namespace DH\DoctrineAuditBundle\Routing;

use Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Routing\RouteCollection;

class DoctrineAuditRoutingLoader extends Loader implements LoaderInterface
{
    /**
     * @var AnnotatedRouteControllerLoader
     */
    private $annotationLoader;

    /**
     * @var array
     */
    private $configuration;

    public function load($resource, ?string $type = null): RouteCollection
    {
        $routeCollection = new RouteCollection();
        if (true === $this->configuration['enabled_viewer']) {
            $routeCollection = $this->annotationLoader->load('DH\DoctrineAuditBundle\Controller\AuditController');
        }

        return $routeCollection;
    }

    public function supports($resource, ?string $type = null): bool
    {
        return 'audit_loader' === $type;
    }

    public function setRoutingLoaderAnnotation(AnnotatedRouteControllerLoader $loader): void
    {
        $this->annotationLoader = $loader;
    }

    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }
}
