<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;
use Symfony\Component\Console\Command\Command;

final class ConsoleUserProvider implements UserProviderInterface
{
    private ?string $currentCommand = null;

    public function __invoke(): ?UserInterface
    {
        if (null === $this->currentCommand) {
            return null;
        }

        // Use command name as both ID and username for better traceability
        return new User(
            $this->currentCommand,
            $this->currentCommand
        );
    }

    public function setCurrentCommand(?Command $command): void
    {
        $this->currentCommand = $command?->getName();
    }
}
