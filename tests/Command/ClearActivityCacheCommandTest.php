<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Command;

use DH\AuditorBundle\Command\ClearActivityCacheCommand;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[Small]
#[CoversClass(ClearActivityCacheCommand::class)]
final class ClearActivityCacheCommandTest extends TestCase
{
    #[Test]
    public function itShowsWarningWhenProviderIsNull(): void
    {
        $command = new ClearActivityCacheCommand(null);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('not configured', $tester->getDisplay());
    }

    #[Test]
    public function itShowsWarningWhenCacheNotAvailable(): void
    {
        $provider = new ActivityGraphProvider(7, false, 300, null);

        $command = new ClearActivityCacheCommand($provider);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('not available', $tester->getDisplay());
    }

    #[Test]
    public function itClearsAllCacheSuccessfully(): void
    {
        $cache = $this->createMock(TagAwareAdapterInterface::class);
        $cache->expects(self::once())
            ->method('invalidateTags')
            ->with([ActivityGraphProvider::CACHE_TAG])
            ->willReturn(true);

        $provider = new ActivityGraphProvider(7, true, 300, $cache);

        $command = new ClearActivityCacheCommand($provider);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('All activity graph cache cleared', $tester->getDisplay());
    }

    #[Test]
    public function itClearsEntityCacheSuccessfully(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->expects(self::once())
            ->method('deleteItem')
            ->willReturn(true);

        $provider = new ActivityGraphProvider(7, true, 300, $cache);

        $command = new ClearActivityCacheCommand($provider);
        $tester = new CommandTester($command);

        $tester->execute(['--entity' => 'App\\Entity\\User']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Cache cleared for entity', $tester->getDisplay());
        self::assertStringContainsString('App\\Entity\\User', $tester->getDisplay());
    }

    #[Test]
    public function itShowsWarningWhenClearAllFailsWithoutTagSupport(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);

        $provider = new ActivityGraphProvider(7, true, 300, $cache);

        $command = new ClearActivityCacheCommand($provider);
        $tester = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Could not clear all cache', $tester->getDisplay());
        self::assertStringContainsString('TagAwareCacheInterface', $tester->getDisplay());
    }

    #[Test]
    public function itShowsWarningWhenClearEntityFails(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache->method('deleteItem')->willReturn(false);

        $provider = new ActivityGraphProvider(7, true, 300, $cache);

        $command = new ClearActivityCacheCommand($provider);
        $tester = new CommandTester($command);

        $tester->execute(['--entity' => 'App\\Entity\\User']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Could not clear cache for entity', $tester->getDisplay());
    }
}
