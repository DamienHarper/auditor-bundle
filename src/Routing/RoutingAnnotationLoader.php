<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Routing;

use DH\AuditorBundle\Controller\ViewerController;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

class RoutingAnnotationLoader extends Loader
{
    private AnnotatedRouteControllerLoader $annotatedRouteControllerLoader;

    private bool $isLoaded = false;

    private array $configuration;

    public function __construct(AnnotatedRouteControllerLoader $annotatedRouteController, array $configuration)
    {
        $this->annotatedRouteControllerLoader = $annotatedRouteController;
        $this->configuration = $configuration;
    }

    /**
     * @param mixed $resource
     */
    public function load($resource, ?string $type = null): RouteCollection
    {
        if ($this->isLoaded) {
            throw new RuntimeException('Do not add the "audit" loader twice');
        }

        $routeCollection = new RouteCollection();
        if (true === $this->configuration['viewer']) {
            $routeCollection = $this->annotatedRouteControllerLoader->load(ViewerController::class);
        }

        $this->isLoaded = true;

        return $routeCollection;
    }

    /**
     * @param mixed   $resource
     * @param ?string $type
     */
    public function supports($resource, $type = null): bool
    {
        return 'auditor' === $type;
    }
}
