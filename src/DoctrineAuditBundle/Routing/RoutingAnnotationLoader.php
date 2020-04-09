<?php

namespace DH\DoctrineAuditBundle\Routing;

use DH\DoctrineAuditBundle\Controller\ViewerController;
use RuntimeException;
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
     * @var bool
     */
    private $isLoaded = false;

    /**
     * @var array
     */
    private $configuration;

    public function __construct(AnnotatedRouteControllerLoader $annotatedRouteController, array $configuration)
    {
        $this->annotatedRouteControllerLoader = $annotatedRouteController;
        $this->configuration = $configuration;
    }

    public function load($resource, ?string $type = null): RouteCollection
    {
        if (true === $this->isLoaded) {
            throw new RuntimeException('Do not add the "audit" loader twice');
        }

        $routeCollection = new RouteCollection();
        if (true === $this->configuration['enabled_viewer']) {
            $routeCollection = $this->annotatedRouteControllerLoader->load(ViewerController::class);
        }

        $this->isLoaded = true;

        return $routeCollection;
    }

    public function supports($resource, ?string $type = null): bool
    {
        return 'audit' === $type;
    }
}
