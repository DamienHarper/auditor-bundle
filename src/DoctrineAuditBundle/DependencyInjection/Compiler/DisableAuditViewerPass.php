<?php

declare(strict_types=1);

namespace DH\DoctrineAuditBundle\DependencyInjection\Compiler;

use DH\DoctrineAuditBundle\Controller\AuditController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class DisableAuditViewerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('dh_doctrine_audit.configuration')) {
            return;
        }

        $config = $container->getParameter('dh_doctrine_audit.configuration');
        if (null === $config['enabled_viewer']) {
            return;
        }

        if (false === $config['enabled_viewer']) {
            $container->removeDefinition(AuditController::class);
        }
    }
}
