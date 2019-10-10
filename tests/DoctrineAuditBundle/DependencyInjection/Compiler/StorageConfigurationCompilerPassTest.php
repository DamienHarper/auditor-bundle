<?php

namespace DH\DoctrineAuditBundle\Tests\DependencyInjection\Compiler;

use DH\DoctrineAuditBundle\DependencyInjection\Compiler\StorageConfigurationCompilerPass;
use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @covers \DH\DoctrineAuditBundle\DependencyInjection\Compiler\StorageConfigurationCompilerPass
 *
 * @internal
 */
final class StorageConfigurationCompilerPassTest extends AbstractCompilerPassTestCase
{
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new StorageConfigurationCompilerPass());
    }
}
