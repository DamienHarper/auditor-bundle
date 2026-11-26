<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Routing;

use DH\AuditorBundle\Tests\App\RoutingControllersKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * The bundle routes are declared through the "auditor" route loader only: importing the
 * "routing.controllers" resource of the Symfony Flex recipe must neither drop the prefix of the
 * application's import nor expose the viewer when it is disabled.
 *
 * @internal
 */
#[Small]
final class RoutingControllersTest extends KernelTestCase
{
    #[DataProvider('provideImportOrderCases')]
    public function testViewerRoutesKeepThePrefixOfTheImport(bool $routingControllersFirst): void
    {
        self::bootKernel(['routing_controllers_first' => $routingControllersFirst, 'viewer_enabled' => true]);

        $routes = $this->auditorRoutes();

        $this->assertNotEmpty($routes, 'The viewer routes must be registered when the viewer is enabled.');
        foreach ($routes as $name => $route) {
            $this->assertStringStartsWith('/admin/audit', $route->getPath(), \sprintf('Route "%s" lost the prefix of the import.', $name));
        }
    }

    #[DataProvider('provideImportOrderCases')]
    public function testNoViewerRouteWhenViewerIsDisabled(bool $routingControllersFirst): void
    {
        self::bootKernel(['routing_controllers_first' => $routingControllersFirst, 'viewer_enabled' => false]);

        $this->assertSame([], array_keys($this->auditorRoutes()), 'A disabled viewer must not expose any route.');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function provideImportOrderCases(): iterable
    {
        yield 'auditor import first' => [false];

        yield 'routing.controllers first' => [true];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new RoutingControllersKernel(
            'test',
            true,
            (bool) ($options['routing_controllers_first'] ?? false),
            (bool) ($options['viewer_enabled'] ?? true),
        );
    }

    /**
     * @return array<string, Route>
     */
    private function auditorRoutes(): array
    {
        $router = self::getContainer()->get('router');
        $this->assertInstanceOf(RouterInterface::class, $router);

        return array_filter(
            $router->getRouteCollection()->all(),
            static fn (string $name): bool => str_starts_with($name, 'dh_auditor_'),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
