<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Event;

use DH\Auditor\Configuration;
use DH\Auditor\User\UserProviderInterface;
use DH\AuditorBundle\User\ConsoleUserProvider;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ConsoleEventSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly ConsoleUserProvider $consoleUserProvider, private readonly Configuration $configuration, private readonly UserProviderInterface $provider) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'registerConsoleUserProvider',
            ConsoleEvents::TERMINATE => 'restoreDefaultUserProvider',
        ];
    }

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
