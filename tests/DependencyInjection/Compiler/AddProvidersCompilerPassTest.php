<?php

namespace DH\AuditorBundle\Tests\DependencyInjection\Compiler;

use DH\AuditorBundle\DependencyInjection\Compiler\AddProviderCompilerPass;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class AddProvidersCompilerPassTest extends AbstractCompilerPassTestCase
{
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new AddProviderCompilerPass());
    }
}
