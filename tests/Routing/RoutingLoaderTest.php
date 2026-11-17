<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Routing;

use DH\AuditorBundle\Controller\ExportController;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\Routing\RoutingLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * @internal
 *
 * @see RoutingLoader
 */
#[Small]
final class RoutingLoaderTest extends TestCase
{
    public function testExportAndViewerRoutesAreLoadedWhenViewerIsEnabled(): void
    {
        $routes = $this->createRoutingLoader(true)->load('.', 'auditor');

        $this->assertSame(['dh_auditor_export', 'dh_auditor_list_audits'], array_keys($routes->all()));
    }

    #[DataProvider('provideNoRouteIsLoadedWhenViewerIsDisabledCases')]
    public function testNoRouteIsLoadedWhenViewerIsDisabled(mixed $viewerConfiguration): void
    {
        $routes = $this->createRoutingLoader($viewerConfiguration)->load('.', 'auditor');

        $this->assertCount(0, $routes, 'The export endpoint must not be exposed when the viewer is disabled.');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideNoRouteIsLoadedWhenViewerIsDisabledCases(): iterable
    {
        yield 'false' => [false];

        yield 'array, disabled' => [['enabled' => false]];

        yield 'array, no enabled key' => [[]];
    }

    private function createRoutingLoader(mixed $viewerConfiguration): RoutingLoader
    {
        $attributeLoader = $this->createStub(AttributeRouteControllerLoader::class);
        $attributeLoader->method('load')->willReturnCallback(static function (mixed $class): RouteCollection {
            $collection = new RouteCollection();
            match ($class) {
                ExportController::class => $collection->add('dh_auditor_export', new Route('/audit/export')),
                ViewerController::class => $collection->add('dh_auditor_list_audits', new Route('/audit')),
                default => null,
            };

            return $collection;
        });

        return new RoutingLoader($attributeLoader, ['viewer' => $viewerConfiguration]);
    }
}
