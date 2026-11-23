<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Event;

use DH\Auditor\Configuration;
use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\Event\ConsoleEventSubscriber;
use DH\AuditorBundle\User\ConsoleUserProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * @internal
 */
#[Small]
final class ConsoleEventSubscriberTest extends TestCase
{
    public function testTheConfiguredUserProviderIsRestoredAfterACommand(): void
    {
        $configuration = new Configuration([]);
        // any callable: a custom user provider need not implement UserProviderInterface
        $custom = static fn (): UserInterface => new User('custom', 'custom user');
        $configuration->setUserProvider($custom);
        $consoleUserProvider = new ConsoleUserProvider();
        $subscriber = new ConsoleEventSubscriber($consoleUserProvider, $configuration, $this->defaultProvider());

        $subscriber->registerConsoleUserProvider($this->commandEvent('app:outer'));
        $this->assertSame($consoleUserProvider, $configuration->getUserProvider(), 'The console user provider is used while the command runs.');

        // a command run from another one
        $subscriber->registerConsoleUserProvider($this->commandEvent('app:inner'));
        $this->assertSame('app:inner', $consoleUserProvider()?->getUsername());
        $subscriber->restoreDefaultUserProvider();
        $this->assertSame($consoleUserProvider, $configuration->getUserProvider(), 'The outer command still runs: the console user provider stays.');
        $this->assertSame('app:outer', $consoleUserProvider()?->getUsername(), 'The outer command is blamed again.');

        $subscriber->restoreDefaultUserProvider();
        $this->assertSame($custom, $configuration->getUserProvider(), 'The configured user provider is restored once the command ends, not the default one.');
        $this->assertNull($consoleUserProvider());
    }

    public function testTheDefaultProviderIsSetWhenNoProviderWasSetBeforeTheCommand(): void
    {
        $configuration = new Configuration([]);
        $default = $this->defaultProvider();
        $subscriber = new ConsoleEventSubscriber(new ConsoleUserProvider(), $configuration, $default);

        $subscriber->registerConsoleUserProvider($this->commandEvent('app:test'));
        $subscriber->restoreDefaultUserProvider();

        $this->assertSame($default, $configuration->getUserProvider());
    }

    public function testATerminateWithoutCommandChangesNothing(): void
    {
        $configuration = new Configuration([]);
        $custom = static fn (): ?UserInterface => null;
        $configuration->setUserProvider($custom);
        $subscriber = new ConsoleEventSubscriber(new ConsoleUserProvider(), $configuration, $this->defaultProvider());

        $subscriber->restoreDefaultUserProvider($this->terminateEvent(new Command('app:test')));

        $this->assertSame($custom, $configuration->getUserProvider());
    }

    public function testTheTerminateOfACommandThisListenerDidNotSeeStartChangesNothing(): void
    {
        $configuration = new Configuration([]);
        $custom = static fn (): ?UserInterface => null;
        $configuration->setUserProvider($custom);
        $consoleUserProvider = new ConsoleUserProvider();
        $subscriber = new ConsoleEventSubscriber($consoleUserProvider, $configuration, $this->defaultProvider());
        $outer = new Command('app:outer');

        $subscriber->registerConsoleUserProvider(new ConsoleCommandEvent($outer, new ArrayInput([]), new NullOutput()));

        // an inner command whose COMMAND event was stopped before this listener
        $subscriber->restoreDefaultUserProvider($this->terminateEvent(new Command('app:inner')));
        $this->assertSame($consoleUserProvider, $configuration->getUserProvider(), 'The outer command still runs.');

        $subscriber->restoreDefaultUserProvider($this->terminateEvent($outer));
        $this->assertSame($custom, $configuration->getUserProvider());
    }

    private function terminateEvent(Command $command): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent($command, new ArrayInput([]), new NullOutput(), Command::SUCCESS);
    }

    private function commandEvent(string $name): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command($name), new ArrayInput([]), new NullOutput());
    }

    private function defaultProvider(): UserProviderInterface
    {
        return new class implements UserProviderInterface {
            public function __invoke(): ?UserInterface
            {
                return null;
            }
        };
    }
}
