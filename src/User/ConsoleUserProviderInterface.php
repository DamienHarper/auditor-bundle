<?php

declare(strict_types=1);

namespace DH\AuditorBundle\User;

use DH\Auditor\User\UserProviderInterface;
use Symfony\Component\Console\Command\Command;

interface ConsoleUserProviderInterface extends UserProviderInterface
{
    public function setCurrentCommand(?Command $command): void;
}
