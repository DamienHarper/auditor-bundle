<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Routing;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\AuditorBundle\Controller\ViewerController;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

class RoutingLoader extends Loader
{
    private bool $isLoaded = false;

    public function __construct(
        private readonly AttributeRouteControllerLoader $attributeRouteControllerLoader,
        private readonly array $configuration,
    ) {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->isLoaded) {
            throw new \RuntimeException('Do not add the "audit" loader twice');
        }

        $routeCollection = new RouteCollection();
        if (Configuration::isViewerEnabledInConfig($this->configuration['viewer'])) {
            $routeCollection = $this->attributeRouteControllerLoader->load(ViewerController::class);
        }

        $this->isLoaded = true;

        return $routeCollection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'auditor' === $type;
    }
}
