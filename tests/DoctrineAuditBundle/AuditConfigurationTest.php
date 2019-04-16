<?php

namespace DH\DoctrineAuditBundle\Tests;

use DH\DoctrineAuditBundle\AuditConfiguration;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Comment;
use DH\DoctrineAuditBundle\Tests\Fixtures\Core\Post;
use DH\DoctrineAuditBundle\User\TokenStorageUserProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security;

/**
 * @covers \DH\DoctrineAuditBundle\AuditConfiguration
 * @covers \DH\DoctrineAuditBundle\Helper\DoctrineHelper
 * @covers \DH\DoctrineAuditBundle\User\TokenStorageUserProvider
 */
class AuditConfigurationTest extends TestCase
{
    public function testDefaultTablePrefix(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertSame('', $configuration->getTablePrefix(), 'table_prefix is empty by default.');
    }

    public function testDefaultTableSuffix(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertSame('_audit', $configuration->getTableSuffix(), 'table_suffix is "_audit" by default.');
    }

    public function testCustomTablePrefix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_prefix' => 'audit_',
        ]);

        $this->assertSame('audit_', $configuration->getTablePrefix(), 'custom table_prefix is "audit_".');
    }

    public function testCustomTableSuffix(): void
    {
        $configuration = $this->getAuditConfiguration([
            'table_suffix' => '_audit_log',
        ]);

        $this->assertSame('_audit_log', $configuration->getTableSuffix(), 'custom table_suffix is "_audit_log".');
    }

    public function testDefaultEnabled(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertTrue($configuration->isEnabled(), 'Enabled by default.');
    }

    public function testEnabled(): void
    {
        $configuration = $this->getAuditConfiguration();
        $configuration->enable();

        $this->assertTrue($configuration->isEnabled(), 'Enabled by default.');
    }

    public function testDisabled(): void
    {
        $configuration = $this->getAuditConfiguration();
        $configuration->disable();

        $this->assertFalse($configuration->isEnabled(), 'Disabled. Global enabled is set to false.');
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

        $this->assertSame($ignored, $configuration->getIgnoredColumns(), 'ignored columns are honored.');
    }

    public function testGetEntities(): void
    {
        $entities = [
            Post::class => null,
            Comment::class => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertSame($entities, $configuration->getEntities(), 'AuditConfiguration::getEntities() returns configured entities list.');
    }

    public function testGetUserProvider(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertInstanceOf(TokenStorageUserProvider::class, $configuration->getUserProvider(), 'UserProvider instanceof TokenStorageUserProvider::class');
    }

    public function testGetRequestStack(): void
    {
        $configuration = $this->getAuditConfiguration();

        $this->assertInstanceOf(RequestStack::class, $configuration->getRequestStack(), 'RequestStack instanceof RequestStack::class');
    }

    public function testIsAudited(): void
    {
        $entities = [
            Post::class => null,
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');
        $this->assertFalse($configuration->isAudited(Comment::class), 'entity "'.Comment::class.'" is not audited.');
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

        $this->assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
        $this->assertTrue($configuration->isAuditable(Post::class), 'entity "'.Post::class.'" is auditable.');
        $this->assertFalse($configuration->isAudited(Comment::class), 'entity "'.Comment::class.'" is not audited.');
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

        $this->assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $this->assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
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

        $this->assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $entities = [
            Post::class => [
                'enabled' => false,
            ],
        ];

        $configuration = $this->getAuditConfiguration([
            'entities' => $entities,
        ]);

        $configuration->enable();

        $this->assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
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

        $this->assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $configuration->disable();

        $this->assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
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

        $this->assertTrue($configuration->isAuditedField(Post::class, 'id'), 'any field is audited.');
        $this->assertTrue($configuration->isAuditedField(Post::class, 'title'), 'any field is audited.');
        $this->assertTrue($configuration->isAuditedField(Post::class, 'created_at'), 'any field is audited.');
        $this->assertTrue($configuration->isAuditedField(Post::class, 'updated_at'), 'any field is audited.');
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

        $this->assertTrue($configuration->isAuditedField(Post::class, 'id'), 'field "'.Post::class.'::$id" is audited.');
        $this->assertTrue($configuration->isAuditedField(Post::class, 'title'), 'field "'.Post::class.'::$title" is audited.');
        $this->assertFalse($configuration->isAuditedField(Post::class, 'created_at'), 'field "'.Post::class.'::$created_at" is not audited.');
        $this->assertFalse($configuration->isAuditedField(Post::class, 'updated_at'), 'field "'.Post::class.'::$updated_at" is not audited.');
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

        $this->assertTrue($configuration->isAuditedField(Post::class, 'id'), 'field "'.Post::class.'::$id" is audited.');
        $this->assertTrue($configuration->isAuditedField(Post::class, 'title'), 'field "'.Post::class.'::$title" is audited.');
        $this->assertFalse($configuration->isAuditedField(Post::class, 'created_at'), 'field "'.Post::class.'::$created_at" is not audited.');
        $this->assertFalse($configuration->isAuditedField(Post::class, 'updated_at'), 'field "'.Post::class.'::$updated_at" is not audited.');
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

        $this->assertFalse($configuration->isAuditedField(Comment::class, 'id'), 'field "'.Comment::class.'::$id" is audited but "'.Comment::class.'" entity is not.');
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

        $this->assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');

        $configuration->enableAuditFor(Post::class);

        $this->assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');
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

        $this->assertTrue($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is audited.');

        $configuration->disableAuditFor(Post::class);

        $this->assertFalse($configuration->isAudited(Post::class), 'entity "'.Post::class.'" is not audited.');
    }

    protected function getAuditConfiguration(array $options = []): AuditConfiguration
    {
        $container = new ContainerBuilder();

        return new AuditConfiguration(
            array_merge([
                'table_prefix' => '',
                'table_suffix' => '_audit',
                'ignored_columns' => [],
                'entities' => [],
                'enabled' => true,
            ], $options),
            new TokenStorageUserProvider(new Security($container)),
            new RequestStack(),
            new FirewallMap($container, [])
        );
    }
}
