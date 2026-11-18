<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\AuditorBundle\DHAuditorBundle;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\Small;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 */
#[Small]
final class LoggerConfigurationTest extends KernelTestCase
{
    /**
     * DoctrineProvider definition captured before the optimization passes.
     *
     * @var null|array{calls: list<array{0: string, 1: array<int|string, mixed>}>, tags: array<string, mixed>}
     */
    private static ?array $definition = null;

    public function testDoctrineProviderIsTaggedForTheAuditorChannel(): void
    {
        $this->bootKernelCapturingTheProvider();

        $this->assertNotNull(self::$definition);
        $this->assertSame([['channel' => 'auditor']], self::$definition['tags']['monolog.logger'] ?? null);

        $setLoggerCalls = array_values(array_filter(self::$definition['calls'], static fn (array $call): bool => 'setLogger' === $call[0]));
        $this->assertCount(1, $setLoggerCalls);

        $reference = $setLoggerCalls[0][1][0] ?? null;
        $this->assertInstanceOf(Reference::class, $reference);
        $this->assertSame('logger', (string) $reference);
        $this->assertSame(ContainerInterface::IGNORE_ON_INVALID_REFERENCE, $reference->getInvalidBehavior());
    }

    public function testDoctrineProviderReceivesTheLogger(): void
    {
        $this->bootKernelCapturingTheProvider();

        $provider = self::getContainer()->get(DoctrineProvider::class);
        $this->assertInstanceOf(DoctrineProvider::class, $provider);
        $this->assertNotInstanceOf(NullLogger::class, $provider->getLogger());
    }

    /**
     * @internal used by the capturing compiler pass
     *
     * @param list<array{0: string, 1: array<int|string, mixed>}> $calls
     * @param array<string, mixed>                                $tags
     */
    public static function capture(array $calls, array $tags): void
    {
        self::$definition = ['calls' => $calls, 'tags' => $tags];
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

    private function bootKernelCapturingTheProvider(): void
    {
        self::$definition = null;

        self::bootKernel(['config' => static function (TestKernel $kernel): void {
            $kernel->setClearCacheAfterShutdown(true);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/dh_auditor.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/security.yaml');

            $kernel->addTestCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    $definition = $container->getDefinition(DoctrineProvider::class);
                    LoggerConfigurationTest::capture($definition->getMethodCalls(), $definition->getTags());
                }
            }, PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }]);
    }
}
