<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection;

use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\DHAuditorBundle;
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
final class RetentionConfigurationTest extends KernelTestCase
{
    public function testRetentionOptionsArePassedToTheDoctrineProvider(): void
    {
        $this->bootWithRetentionConfig('valid');

        $configuration = self::getContainer()->get(DoctrineProviderConfiguration::class);
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, $configuration);
        $entities = $configuration->getEntities();

        $this->assertSame('P1Y', $entities[Author::class]['max_age']);
        $this->assertSame(100, $entities[Author::class]['max_entries']);

        // Only the given keys are set, so that the other one can still come from #[Auditable].
        $this->assertSame('P30D', $entities[Post::class]['max_age']);
        $this->assertArrayNotHasKey('max_entries', $entities[Post::class]);

        foreach ([Comment::class, Tag::class] as $entity) {
            $this->assertArrayNotHasKey('max_age', $entities[$entity]);
            $this->assertArrayNotHasKey('max_entries', $entities[$entity]);
        }

        foreach ([Author::class, Post::class, Comment::class, Tag::class] as $entity) {
            $this->assertTrue($entities[$entity]['enabled']);
            $this->assertArrayNotHasKey('retention', $entities[$entity]);
        }
    }

    #[DataProvider('provideInvalidRetentionConfigFailsAtContainerBuildCases')]
    public function testInvalidRetentionConfigFailsAtContainerBuild(string $config, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->bootWithRetentionConfig($config);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidRetentionConfigFailsAtContainerBuildCases(): iterable
    {
        yield 'zero max_age' => ['invalid_max_age', \sprintf('Invalid "retention" option for entity "%s": Invalid retention "maxAge" value "P0D"', Post::class)];

        yield 'zero max_entries' => ['invalid_max_entries', \sprintf('Invalid "retention" option for entity "%s": Invalid retention "maxEntries" value 0', Post::class)];

        yield 'non integer max_entries' => ['invalid_max_entries_type', \sprintf('The "retention.max_entries" option of entity "%s" must be an integer, string given.', Post::class)];

        yield 'unknown option' => ['unknown_option', \sprintf('Unknown "retention" option(s) "max_days" for entity "%s"', Post::class)];
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

    private function bootWithRetentionConfig(string $name): void
    {
        self::bootKernel(['config' => static function (TestKernel $kernel) use ($name): void {
            $kernel->setClearCacheAfterShutdown(false);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/retention/'.$name.'.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/security.yaml');
        }]);
    }
}
