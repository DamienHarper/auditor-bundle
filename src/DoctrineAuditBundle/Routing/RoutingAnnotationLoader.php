<?php

namespace DH\DoctrineAuditBundle\Routing;

use DH\DoctrineAuditBundle\Controller\ViewerController;
use Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

class RoutingAnnotationLoader extends Loader
{
    /**
     * @var AnnotatedRouteControllerLoader
     */
    private $annotatedRouteControllerLoader;

    /**
     * @var array
     */
    private $configuration;

    public function load($resource, ?string $type = null): RouteCollection
    {
        $routeCollection = new RouteCollection();
        if (true === $this->configuration['enabled_viewer']) {
            $routeCollection = $this->annotatedRouteControllerLoader->load(ViewerController::class);
        }

        return $routeCollection;
    }

    public function supports($resource, ?string $type = null): bool
    {
        return 'audit_annotation' === $type;
    }

    public function setAnnotatedRouteControllerLoader(AnnotatedRouteControllerLoader $annotatedRouteController): void
    {
        $this->annotatedRouteControllerLoader = $annotatedRouteController;
    }

    public function setConfiguration(array $configuration): void
    {
        $this->configuration = $configuration;
    }
}
