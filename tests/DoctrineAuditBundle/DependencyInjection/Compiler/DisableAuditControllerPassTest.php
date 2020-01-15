<?php

declare(strict_types=1);

namespace DH\DoctrineAuditBundle\DependencyInjection\Compiler;

use Matthias\SymfonyDependencyInjectionTest\PhpUnit\AbstractCompilerPassTestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class DisableAuditControllerPassTest extends AbstractCompilerPassTestCase
{
    protected function registerCompilerPass(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new DisableAuditViewerPass());
    }
}
