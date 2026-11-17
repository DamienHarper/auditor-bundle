<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Routing;

use DH\Auditor\Provider\Doctrine\Configuration;
use DH\AuditorBundle\Controller\ExportController;
use DH\AuditorBundle\Controller\ViewerController;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Routing\RouteCollection;

#[AutoconfigureTag('routing.loader')]
final class RoutingLoader extends Loader
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

        // The export endpoint exposes the same data as the viewer, so it shares its on/off switch:
        // applications that disabled the viewer never get an /audit/export route they did not secure.
        if (Configuration::isViewerEnabledInConfig($this->configuration['viewer'])) {
            // Export routes must be added first so /audit/export takes priority over /audit/{entity}.
            $routeCollection->addCollection($this->attributeRouteControllerLoader->load(ExportController::class));
            $routeCollection->addCollection($this->attributeRouteControllerLoader->load(ViewerController::class));
        }

        $this->isLoaded = true;

        return $routeCollection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'auditor' === $type;
    }
}
