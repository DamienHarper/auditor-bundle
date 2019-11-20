<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\Annotation\AnnotationLoader;
use DH\DoctrineAuditBundle\Annotation\Security;
use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Annotation\AuditedEntity;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Annotation\UnauditedEntity;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Standard\Post;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security as CoreSecurity;

/**
 * @internal
 */
final class AuditConfigurationTest extends BaseTest
{
    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    public function testDefaultTablePrefix(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertSame('', $configuration->getTablePrefix(), 'table_prefix is empty by default.');
    }

    public function testDefaultTableSuffix(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertSame('_audit', $configuration->getTableSuffix(), 'table_suffix is "_audit" by default.');
    }

    public function testCustomTablePrefix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_prefix' => 'audit_',
        ]);

        self::assertSame('audit_', $configuration->getTablePrefix(), 'custom table_prefix is "audit_".');
    }

    public function testCustomTableSuffix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_suffix' => '_audit_log',
        ]);

        self::assertSame('_audit_log', $configuration->getTableSuffix(), 'custom table_suffix is "_audit_log".');
    }

    public function testDefaultEnabled(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertTrue($configuration->isEnabled(), 'Enabled by default.');
    }

    public function testEnabled(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertTrue($configuration->isEnabled(), 'Enabled by default.');
    }

    public function testDisabled(): void
    {
        $configuration = $this->getAuditConfiguration();
        $configuration->disable();

        self::assertFalse($configuration->isEnabled(), 'Disabled. Global enabled is set to false.');
    }

    public function testGloballyIgnoredColumns(): void
    {
        $ignored = [
            'created_at',
            'updated_at',
        ];

        $configuration = $this->getAuditConfiguration([
            'ignored_columns' => $ignored,
        ]);

        self::assertSame($ignored, $configuration->getIgnoredColumns(), 'ignored columns are honored.');
    }

    public function testGetEntities(): void
    {
        $entities = [
            Post::class => null,
            Comment::class => null,
            AuditedEntity::class => [
                'ignored_columns' => ['ignoredField'],
                'enabled' => true,
                'roles' => null,
            ],
            UnauditedEntity::class => [
                'ignored_columns' => ['ignoredField'],
                'enabled' => false,
                'roles' => [
                    Security::VIEW_SCOPE => ['ROLE1', 'ROLE2'],
                ],
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertSame($entities, $configuration->getEntities(), 'AuditConfiguration::getEntities() returns configured entities list.');
    }

    public function testGetUserProvider(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertInstanceOf(TokenStorageUserProvider::class, $configuration->getUserProvider(), 'UserProvider instanceof TokenStorageUserProvider::class');
    }

    public function testGetRequestStack(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertInstanceOf(RequestStack::class, $configuration->getRequestStack(), 'RequestStack instanceof RequestStack::class');
    }

    public function testIsAudited(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');
        self::assertFalse($configuration->isAudited(Comment::class), 'entity "'.Comment::class.'" is not audited.');
    }

    public function testIsAuditable(): void
    {
        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
        self::assertTrue($configuration->isAuditable(Post::class), 'entity "'.Post::class.'" is auditable.');
        self::assertFalse($configuration->isAudited(Comment::class), 'entity "'.Comment::class.'" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedHonorsEnabledFlag(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedWhenAuditIsEnabled(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $configuration->enable();

        self::assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $configuration->enable();

        self::assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedWhenAuditIsDisabled(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $configuration->disable();

        self::assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
    }

    /**
     * @depends testIsAudited
     */
    public function testIsAuditedFieldAuditsAnyFieldByDefault(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAuditedField(Post::class, 'id'), 'any field is audited.');
        self::assertTrue($configuration->isAuditedField(Post::class, 'title'), 'any field is audited.');
        self::assertTrue($configuration->isAuditedField(Post::class, 'created_at'), 'any field is audited.');
        self::assertTrue($configuration->isAuditedField(Post::class, 'updated_at'), 'any field is audited.');
    }

    /**
     * @depends testIsAuditedFieldAuditsAnyFieldByDefault
     */
    public function testIsAuditedFieldHonorsLocallyIgnoredColumns(): void
    {
        $entities = [
            Post::class => [
                'ignored_columns' => [
                    'created_at',
                    'updated_at',
                ],
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAuditedField(Post::class, 'id'), 'field "'.Post::class.'::$id" is audited.');
        self::assertTrue($configuration->isAuditedField(Post::class, 'title'), 'field "'.Post::class.'::$title" is audited.');
        self::assertFalse($configuration->isAuditedField(Post::class, 'created_at'), 'field "'.Post::class.'::$created_at" is not audited.');
        self::assertFalse($configuration->isAuditedField(Post::class, 'updated_at'), 'field "'.Post::class.'::$updated_at" is not audited.');
    }

    /**
     * @depends testIsAuditedFieldHonorsLocallyIgnoredColumns
     */
    public function testIsAuditedFieldHonorsGloballyIgnoredColumns(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'ignored_columns' => [
                'created_at',
                'updated_at',
            ],
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAuditedField(Post::class, 'id'), 'field "'.Post::class.'::$id" is audited.');
        self::assertTrue($configuration->isAuditedField(Post::class, 'title'), 'field "'.Post::class.'::$title" is audited.');
        self::assertFalse($configuration->isAuditedField(Post::class, 'created_at'), 'field "'.Post::class.'::$created_at" is not audited.');
        self::assertFalse($configuration->isAuditedField(Post::class, 'updated_at'), 'field "'.Post::class.'::$updated_at" is not audited.');
    }

    /**
     * @depends testIsAuditedFieldHonorsLocallyIgnoredColumns
     */
    public function testIsAuditedFieldReturnsFalseIfEntityIsNotAudited(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'ignored_columns' => [
                'created_at',
                'updated_at',
            ],
            'entities' => $entities,
        ]);

        self::assertFalse($configuration->isAuditedField(Comment::class, 'id'), 'field "'.Comment::class.'::$id" is audited but "'.Comment::class.'" entity is not.');
    }

    /**
     * @depends testIsAuditedHonorsEnabledFlag
     */
    public function testEnableAuditFor(): void
    {
        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');

        $configuration->enableAuditFor(Post::class);

        self::assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');
    }

    /**
     * @depends testIsAuditedHonorsEnabledFlag
     */
    public function testDisableAuditFor(): void
    {
        $entities = [
            Post::class => [
                'enabled' => true,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        self::assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $configuration->disableAuditFor(Post::class);

        self::assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
    }

    public function testDefaultTimezone(): void
    {
        $configuration = $this->getAuditConfiguration();

        self::assertSame('UTC', $configuration->getTimezone(), 'timezone is UTC by default.');
    }

    public function testCustomTimezone(): void
    {
        $configuration = $this->getAuditConfiguration([
            'timezone' => 'Europe/London',
        ]);

        self::assertSame('Europe/London', $configuration->getTimezone(), 'custom timezone is "Europe/London".');
    }

    protected function getAuditConfiguration(array $options = [], ?EntityManager $entityManager = null): AuditConfiguration
    {
        $container = new ContainerBuilder();
        $em = $entityManager ?? $this->getEntityManager();

        return new AuditConfiguration(
            array_merge([
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'timezone' => 'UTC',
                'ignored_columns' => [],
                'entities' => [],
                'enabled' => true,
            ], $options),
            new TokenStorageUserProvider(new CoreSecurity($container)),
            new RequestStack(),
            new FirewallMap($container, []),
            $em,
            new AnnotationLoader($em),
            new EventDispatcher()
        );
    }
}
