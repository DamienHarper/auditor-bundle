<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Annotation\AnnotationLoader;
use DH\DoctrineAuditBundle\Annotation\Security;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Annotation\AuditedEntity;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Annotation\UnauditedEntity;

/**
 * @internal
 */
final class AnnotationLoaderTest extends BaseTest
{
    public function testLoad(): void
    {
        $em = $this->getEntityManager();
        $annotationLoader = new AnnotationLoader($em);

        $config = $annotationLoader->load();
        static::assertCount(2, $config);

        $options = $config[AuditedEntity::class];
        static::assertSame($options['ignored_columns'], ['ignoredField']);
        static::assertTrue($options['enabled']);
        static::assertNull($options['roles']);

        $options = $config[UnauditedEntity::class];
        static::assertSame($options['ignored_columns'], ['ignoredField']);
        static::assertFalse($options['enabled']);
        static::assertSame(
            [Security::VIEW_SCOPE => ['ROLE1', 'ROLE2']],
            $options['roles']
        );
    }
}
