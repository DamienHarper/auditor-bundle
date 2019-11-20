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
        self::assertCount(2, $config);

        $options = $config[AuditedEntity::class];
        self::assertSame($options['ignored_columns'], ['ignoredField']);
        self::assertTrue($options['enabled']);
        self::assertNull($options['roles']);

        $options = $config[UnauditedEntity::class];
        self::assertSame($options['ignored_columns'], ['ignoredField']);
        self::assertFalse($options['enabled']);
        self::assertSame(
            [Security::VIEW_SCOPE => ['ROLE1', 'ROLE2']],
            $options['roles']
        );
    }
}
