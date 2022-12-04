<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;
use DH\Auditor\User\UserProviderInterface;
use Symfony\Component\Console\Command\Command;

class ConsoleUserProvider implements UserProviderInterface
{
    private ?string $currentCommand = null;

    public function __invoke(): ?UserInterface
    {
        if (null === $this->currentCommand) {
            return null;
        }

        return new User(
            'command',
            $this->currentCommand ?? ''
        );
    }

    public function setCurrentCommand(?Command $command): void
    {
        $this->currentCommand = null === $command ? null : $command->getName();
    }
}
