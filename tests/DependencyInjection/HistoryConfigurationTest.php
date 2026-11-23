<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection;

use DH\Auditor\Contract\FieldLabelResolverInterface;
use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\History\TranslatedFieldLabelResolver;
use DH\AuditorBundle\Tests\Fixtures\History\NoopReadScope;
use DH\AuditorBundle\Tests\Fixtures\History\PostRootResolver;
use DH\AuditorBundle\Tests\Fixtures\History\UppercaseLabelResolver;
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
final class HistoryConfigurationTest extends KernelTestCase
{
    public function testHistoryOptionsArePassedToTheDoctrineProvider(): void
    {
        $this->bootWithHistoryConfig('valid');

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);

        $this->assertSame(['author'], $configuration->getRoots(Post::class));
        $this->assertSame(['post', 'post.author'], $configuration->getRoots(Comment::class));
        $this->assertInstanceOf(PostRootResolver::class, $configuration->getRootResolver());
        $this->assertInstanceOf(UppercaseLabelResolver::class, $configuration->getEntityLabelResolver());
        $this->assertInstanceOf(NoopReadScope::class, $configuration->getReadScope());

        $parameter = self::getContainer()->getParameter('dh_auditor.provider.doctrine.configuration');
        $this->assertIsArray($parameter);
        $this->assertArrayNotHasKey('root_resolver', $parameter, 'Services are not passed as parameters.');

        $this->assertInstanceOf(TranslatedFieldLabelResolver::class, self::getContainer()->get(FieldLabelResolverInterface::class));
    }

    public function testHistoryOptionsAreNullByDefault(): void
    {
        $this->bootWithHistoryConfig(null);

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);
        $this->assertNull($configuration->getRootResolver());
        $this->assertNull($configuration->getEntityLabelResolver());
        $this->assertNull($configuration->getReadScope());
    }

    #[DataProvider('provideInvalidHistoryConfigFailsAtContainerBuildCases')]
    public function testInvalidHistoryConfigFailsAtContainerBuild(string $config, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->bootWithHistoryConfig($config);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidHistoryConfigFailsAtContainerBuildCases(): iterable
    {
        yield 'invalid root path' => ['invalid_roots', \sprintf('The "roots" option of entity "%s" is invalid', Post::class)];

        yield 'roots not a list' => ['roots_not_a_list', \sprintf('The "roots" option of entity "%s" must be a list of property paths.', Post::class)];

        yield 'roots not strings' => ['roots_not_strings', \sprintf('The "roots" option of entity "%s" must be a list of property paths.', Post::class)];

        yield 'read scope not a service ID' => ['invalid_read_scope', 'The "read_scope" option of the doctrine provider must be a service ID, int given.'];

        yield 'empty read scope' => ['empty_read_scope', 'The "read_scope" option of the doctrine provider must be a service ID, string given.'];
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

    private function bootWithHistoryConfig(?string $name): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($name): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(null === $name
                ? __DIR__.'/../Fixtures/Resources/config/dh_auditor.yaml'
                : __DIR__.'/../Fixtures/Resources/config/history/'.$name.'.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/security.yaml');
        }]);
    }
}
