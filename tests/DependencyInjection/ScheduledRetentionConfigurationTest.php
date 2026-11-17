<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\DependencyInjection;

use DH\Auditor\Provider\Doctrine\Configuration as DoctrineProviderConfiguration;
use DH\AuditorBundle\DHAuditorBundle;
use DH\AuditorBundle\Retention\PruneAuditLogsHandler;
use DH\AuditorBundle\Retention\RetentionSchedule;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nyholm\BundleTest\TestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * @internal
 */
#[Small]
final class ScheduledRetentionConfigurationTest extends KernelTestCase
{
    /**
     * Definitions captured before private services are removed.
     *
     * @var array<string, array{class: ?string, arguments: array<int|string, mixed>, tags: array<string, mixed>}>
     */
    private static array $definitions = [];

    public function testScheduledPruningIsDisabledByDefault(): void
    {
        $this->bootWith(__DIR__.'/../Fixtures/Resources/config/dh_auditor.yaml', withMessenger: true);

        $this->assertArrayNotHasKey(PruneAuditLogsHandler::class, self::$definitions);
        $this->assertArrayNotHasKey(RetentionSchedule::class, self::$definitions);
        $this->assertArrayNotHasKey('messenger.transport.scheduler_'.RetentionSchedule::NAME, self::$definitions);
    }

    public function testScheduledPruningRegistersTheScheduleAndTheHandler(): void
    {
        $this->bootWith(__DIR__.'/../Fixtures/Resources/config/retention/scheduled.yaml', withMessenger: true);

        $this->assertArrayHasKey(RetentionSchedule::class, self::$definitions);
        $this->assertSame(['0 3 * * *'], self::$definitions[RetentionSchedule::class]['arguments']);
        $this->assertSame([['name' => RetentionSchedule::NAME]], self::$definitions[RetentionSchedule::class]['tags']['scheduler.schedule_provider']);

        $this->assertArrayHasKey(PruneAuditLogsHandler::class, self::$definitions);
        $this->assertSame('P2Y', self::$definitions[PruneAuditLogsHandler::class]['arguments'][1]);
        $this->assertArrayHasKey('messenger.message_handler', self::$definitions[PruneAuditLogsHandler::class]['tags']);

        // Symfony Scheduler turns the schedule into a dedicated transport.
        $this->assertArrayHasKey('messenger.transport.scheduler_'.RetentionSchedule::NAME, self::$definitions);
    }

    public function testRetentionSettingsAreNotPassedToTheProviderConfiguration(): void
    {
        $this->bootWith(__DIR__.'/../Fixtures/Resources/config/retention/scheduled.yaml', withMessenger: true);

        // Building the provider configuration fails on unknown options.
        $this->assertInstanceOf(DoctrineProviderConfiguration::class, self::getContainer()->get(DoctrineProviderConfiguration::class));
    }

    #[DataProvider('provideInvalidScheduledRetentionConfigFailsAtContainerBuildCases')]
    public function testInvalidScheduledRetentionConfigFailsAtContainerBuild(string $config, string $expectedMessage): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageIsOrContains($expectedMessage);

        $this->bootWith(__DIR__.'/../Fixtures/Resources/config/retention/'.$config.'.yaml', withMessenger: false);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideInvalidScheduledRetentionConfigFailsAtContainerBuildCases(): iterable
    {
        yield 'invalid cron expression' => ['invalid_schedule', 'The "retention.schedule" option of the doctrine provider must be a valid cron expression, "every night" given.'];

        yield 'zero default max age' => ['invalid_default_max_age', 'Invalid "retention.default_max_age" option for the doctrine provider: Invalid retention "maxAge" value "P0D"'];

        yield 'unknown option' => ['unknown_scheduled_option', 'Unknown "retention" option(s) "cron" for the doctrine provider'];
    }

    /**
     * @internal used by the capturing compiler pass
     *
     * @param array<int|string, mixed> $arguments
     * @param array<string, mixed>     $tags
     */
    public static function capture(string $id, ?string $class, array $arguments, array $tags): void
    {
        self::$definitions[$id] = ['class' => $class, 'arguments' => $arguments, 'tags' => $tags];
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

    private function bootWith(string $configFile, bool $withMessenger): void
    {
        self::$definitions = [];

        self::bootKernel(['config' => static function (TestKernel $kernel) use ($configFile, $withMessenger): void {
            $kernel->setClearCacheAfterShutdown(true);
            $kernel->addTestBundle(DoctrineBundle::class);
            $kernel->addTestBundle(SecurityBundle::class);
            $kernel->addTestBundle(TwigBundle::class);
            $kernel->addTestConfig($configFile);
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/doctrine.yaml');
            $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/security.yaml');
            if ($withMessenger) {
                $kernel->addTestConfig(__DIR__.'/../Fixtures/Resources/config/retention/messenger.yaml');
            }

            $kernel->addTestCompilerPass(new class implements CompilerPassInterface {
                public function process(ContainerBuilder $container): void
                {
                    foreach ([PruneAuditLogsHandler::class, RetentionSchedule::class, 'messenger.transport.scheduler_'.RetentionSchedule::NAME] as $id) {
                        if ($container->hasDefinition($id)) {
                            $definition = $container->getDefinition($id);
                            ScheduledRetentionConfigurationTest::capture($id, $definition->getClass(), $definition->getArguments(), $definition->getTags());
                        }
                    }
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        }]);
    }
}
