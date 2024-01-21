<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

if (BaseKernel::MAJOR_VERSION >= 6) {
    class Kernel extends BaseKernel
    {
        use MicroKernelTrait;

        private const CONFIG_EXTS = '.{php,xml,yaml,yml}';

        public function registerBundles(): iterable
        {
            $contents = require $this->getProjectDir().'/config/bundles.php';
            foreach ($contents as $class => $envs) {
                if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                    yield new $class();
                }
            }
        }

        public function getProjectDir(): string
        {
            return \dirname(__DIR__).'/App';
        }

        protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
        {
            $container->addResource(new FileResource($this->getProjectDir().'/config/bundles.php'));
            $container->setParameter('.container.dumper.inline_class_loader', \PHP_VERSION_ID < 70400 || $this->debug);
            $container->setParameter('.container.dumper.inline_factories', true);

            $confDir = $this->getProjectDir().'/config';
            $loader->load($confDir.'/services'.self::CONFIG_EXTS, 'glob');
            $loader->load($confDir.'/{packages}/*'.self::CONFIG_EXTS, 'glob');
            $loader->load($confDir.'/{packages}/sf6_7/*'.self::CONFIG_EXTS, 'glob');
        }

        protected function configureRoutes(RoutingConfigurator $routes): void
        {
            $confDir = $this->getProjectDir().'/config';

            $routes->import($confDir.'/routes/*'.self::CONFIG_EXTS, 'glob');
            $routes->import($confDir.'/routes/sf6_7/*'.self::CONFIG_EXTS, 'glob');
        }
    }
} else {
    class Kernel extends BaseKernel
    {
        use MicroKernelTrait;

        private const CONFIG_EXTS = '.{php,xml,yaml,yml}';

        public function registerBundles(): iterable
        {
            $contents = require $this->getProjectDir().'/config/bundles.php';
            foreach ($contents as $class => $envs) {
                if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                    yield new $class();
                }
            }
        }

        public function getProjectDir(): string
        {
            return \dirname(__DIR__).'/App';
        }

        protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
        {
            $container->addResource(new FileResource($this->getProjectDir().'/config/bundles.php'));
            $container->setParameter('container.dumper.inline_class_loader', \PHP_VERSION_ID < 70400 || $this->debug);
            $container->setParameter('container.dumper.inline_factories', true);

            $confDir = $this->getProjectDir().'/config';
            $loader->load($confDir.'/services_legacy'.self::CONFIG_EXTS, 'glob');
            $loader->load($confDir.'/{packages}/*'.self::CONFIG_EXTS, 'glob');
            $loader->load($confDir.'/{packages}/sf4_5/*'.self::CONFIG_EXTS, 'glob');
        }

        protected function configureRoutes(RoutingConfigurator $routes): void
        {
            $confDir = $this->getProjectDir().'/config';

            $routes->import($confDir.'/routes/*'.self::CONFIG_EXTS, 'glob');
            $routes->import($confDir.'/routes/sf4_5/*'.self::CONFIG_EXTS, 'glob');
        }
    }
}
