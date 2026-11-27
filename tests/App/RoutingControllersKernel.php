<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\App;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Test application that imports the bundle routes under a prefix next to the "routing.controllers"
 * resource of the symfony/routing recipe (config/routes.yaml), in a given order, with the viewer enabled or not.
 */
final class RoutingControllersKernel extends Kernel
{
    public function __construct(
        string $environment,
        bool $debug,
        private readonly bool $routingControllersFirst,
        private readonly bool $viewerEnabled,
    ) {
        parent::__construct($environment, $debug);
    }

    #[\Override]
    public function getCacheDir(): string
    {
        return \sprintf(
            '%s/var/cache/routing_controllers/%s_%s_%s',
            $this->getProjectDir(),
            $this->environment,
            $this->routingControllersFirst ? 'controllers_first' : 'auditor_first',
            $this->viewerEnabled ? 'viewer' : 'no_viewer',
        );
    }

    #[\Override]
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);

        $viewerEnabled = $this->viewerEnabled;
        $loader->load(static function (ContainerBuilder $container) use ($viewerEnabled): void {
            $container->loadFromExtension('dh_auditor', [
                'providers' => [
                    'doctrine' => ['viewer' => $viewerEnabled],
                ],
            ]);
        });
    }

    #[\Override]
    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->routingControllersFirst) {
            $routes->import('routing.controllers');
        }

        $routes->import('@DHAuditorBundle/Controller/', 'auditor')->prefix('/admin');

        if (!$this->routingControllersFirst) {
            $routes->import('routing.controllers');
        }
    }
}
