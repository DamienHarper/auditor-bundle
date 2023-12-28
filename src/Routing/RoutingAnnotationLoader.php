<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Routing;

use DH\AuditorBundle\Controller\ViewerController;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

class RoutingAnnotationLoader extends Loader
{
    private AttributeRouteControllerLoader $attributeRouteControllerLoader;

    private bool $isLoaded = false;

    private array $configuration;

    public function __construct(AttributeRouteControllerLoader $attributeRouteControllerLoader, array $configuration)
    {
        $this->attributeRouteControllerLoader = $attributeRouteControllerLoader;
        $this->configuration = $configuration;
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->isLoaded) {
            throw new RuntimeException('Do not add the "audit" loader twice');
        }

        $routeCollection = new RouteCollection();
        if (true === $this->configuration['viewer']) {
            $routeCollection = $this->attributeRouteControllerLoader->load(ViewerController::class);
        }

        $this->isLoaded = true;

        return $routeCollection;
    }

    /**
     * @param ?string $type
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'auditor' === $type;
    }
}
