<?php

namespace DH\DoctrineAuditBundle\Tests\Routing;

use DH\DoctrineAuditBundle\Routing\RoutingAnnotationLoader;
use DH\DoctrineAuditBundle\Tests\CoreTest;
use Symfony\Bundle\FrameworkBundle\Routing\AnnotatedRouteControllerLoader;

class RoutingAnnotationLoaderTest extends CoreTest
{
    public function testSupportsReturnsTrue()
    {
        $annotatedRouteController = $this->prophesize(AnnotatedRouteControllerLoader::class);
        $routingAnnotationLoader = new RoutingAnnotationLoader($annotatedRouteController->reveal(), []);

        $result = $routingAnnotationLoader->supports(null, 'audit');

        $this->assertTrue($result);
    }
}
