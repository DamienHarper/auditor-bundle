<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Configuration;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\User\ConsoleUserProvider;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'registerConsoleUserProvider')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'restoreDefaultUserProvider')]
final class ConsoleEventSubscriber
{
    /**
     * The running commands and the user provider in place before each of them, innermost last.
     *
     * @var list<array{command: ?Command, previousProvider: ?callable}>
     */
    private array $commands = [];

    public function __construct(
        private readonly ConsoleUserProvider $consoleUserProvider,
        private readonly Configuration $configuration,
        private readonly UserProviderInterface $provider,
    ) {}

    public function registerConsoleUserProvider(ConsoleCommandEvent $commandEvent): void
    {
        $command = $commandEvent->getCommand();
        $this->consoleUserProvider->setCurrentCommand($command);
        // restored when the command ends: the provider set before it, which may be a custom one (not the default)
        $this->commands[] = ['command' => $command, 'previousProvider' => $this->configuration->getUserProvider()];
        $this->configuration->setUserProvider($this->consoleUserProvider);
    }

    public function restoreDefaultUserProvider(?ConsoleTerminateEvent $terminateEvent = null): void
    {
        $key = array_key_last($this->commands);
        // a command this listener did not see start (another COMMAND listener stopped the event): nothing to restore
        if (null === $key || ($terminateEvent instanceof ConsoleTerminateEvent && $this->commands[$key]['command'] !== $terminateEvent->getCommand())) {
            return;
        }

        $ended = array_pop($this->commands);
        // a command run from another one: the outer command is blamed again
        $outer = [] === $this->commands ? null : $this->commands[array_key_last($this->commands)];
        $this->consoleUserProvider->setCurrentCommand($outer['command'] ?? null);
        $this->configuration->setUserProvider($ended['previousProvider'] ?? $this->provider);
    }
}
