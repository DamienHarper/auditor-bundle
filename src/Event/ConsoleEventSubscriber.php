<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Configuration;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\User\ConsoleUserProvider;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: ConsoleEvents::COMMAND, method: 'registerConsoleUserProvider')]
#[AsEventListener(event: ConsoleEvents::TERMINATE, method: 'restoreDefaultUserProvider')]
final readonly class ConsoleEventSubscriber
{
    public function __construct(
        private ConsoleUserProvider $consoleUserProvider,
        private Configuration $configuration,
        private UserProviderInterface $provider,
    ) {}

    public function registerConsoleUserProvider(ConsoleCommandEvent $commandEvent): void
    {
        $command = $commandEvent->getCommand();
        $this->consoleUserProvider->setCurrentCommand($command);
        $this->configuration->setUserProvider($this->consoleUserProvider);
    }

    public function restoreDefaultUserProvider(): void
    {
        $this->consoleUserProvider->setCurrentCommand(null);
        $this->configuration->setUserProvider($this->provider);
    }
}
