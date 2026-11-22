<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection;

use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Tests\Fixtures\Columns\PostIgnoredColumnsResolver;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 */
#[Small]
final class ColumnsConfigurationTest extends KernelTestCase
{
    public function testColumnsOptionsArePassedToTheDoctrineProvider(): void
    {
        $this->bootWithColumnsConfig('valid');

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);
        $entities = $configuration->getEntities();

        $this->assertSame(['title', 'body'], $entities[Post::class]['audited_columns']);
        $this->assertSame(['body'], $entities[Post::class]['ignored_columns']);
        $this->assertNull($entities[Comment::class]['audited_columns']);

        $this->assertInstanceOf(PostIgnoredColumnsResolver::class, $configuration->getIgnoredColumnsResolver());

        $parameter = self::getContainer()->getParameter('dh_auditor.provider.doctrine.configuration');
        $this->assertIsArray($parameter);
        $this->assertArrayNotHasKey('ignored_columns_resolver', $parameter, 'The resolver service is not passed as a parameter.');
    }

    public function testColumnsOptionsDecideWhichFieldsAreAudited(): void
    {
        $this->bootWithColumnsConfig('valid');

        $provider = self::getContainer()->get(DoctrineProvider::class);
        $this->assertInstanceOf(DoctrineProvider::class, $provider);

        $this->assertTrue($provider->isAuditedField(Post::class, 'title'), 'An allowlisted field is audited.');
        $this->assertFalse($provider->isAuditedField(Post::class, 'body'), 'An ignored field is not audited, even when allowlisted.');
        $this->assertFalse($provider->isAuditedField(Post::class, 'created_at'), 'A field missing from the allowlist is not audited.');

        $public = new Post()->setTitle('Public');
        $secret = new Post()->setTitle('Secret');
        $this->assertTrue($provider->isAuditedField($public, 'title'), 'The resolver audits the title of a public post.');
        $this->assertFalse($provider->isAuditedField($secret, 'title'), 'The resolver ignores the title of a secret post.');
        $this->assertTrue($provider->isAuditedField(Comment::class, 'body'), 'Every field of an entity without allowlist is audited.');
    }

    public function testIgnoredColumnsResolverIsNullByDefault(): void
    {
        $this->bootWithColumnsConfig(null);

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);
        $this->assertNull($configuration->getIgnoredColumnsResolver());
    }

    #[DataProvider('provideInvalidColumnsConfigFailsAtContainerBuildCases')]
    public function testInvalidColumnsConfigFailsAtContainerBuild(string $config, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->bootWithColumnsConfig($config);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidColumnsConfigFailsAtContainerBuildCases(): iterable
    {
        yield 'audited_columns not a list' => ['invalid_audited_columns', \sprintf('The "audited_columns" option of entity "%s" must be a list of property names.', Post::class)];

        yield 'ignored_columns not a list' => ['invalid_ignored_columns', \sprintf('The "ignored_columns" option of entity "%s" must be a list of property names.', Post::class)];

        yield 'resolver not a service ID' => ['invalid_resolver', 'The "ignored_columns_resolver" option of the doctrine provider must be a service ID, array given.'];
    }

    #[\Override]
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    #[\Override]
    protected static function createKernel(array $options = []): KernelInterface
    {
        /**
         * @var TestKernel $kernel
         */
        $kernel = parent::createKernel($options);
        $kernel->addTestBundle(DHAuditorBundle::class);
        $kernel->handleOptions($options);

        return $kernel;
    }

    private function bootWithColumnsConfig(?string $name): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($name): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(null === $name
                ? __DIR__.'/../Fixtures/Resources/config/dh_auditor.yaml'
                : __DIR__.'/../Fixtures/Resources/config/columns/'.$name.'.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/security.yaml');
        }]);
    }
}
