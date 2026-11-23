<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\User;

use DH\Auditor\User\User;
use DH\Auditor\User\UserInterface;

/**
 * A user provider set with the "user_provider" option: any callable, it need not implement UserProviderInterface.
 */
final class CustomUserProvider
{
    public function __invoke(): UserInterface
    {
        return new User('custom', 'custom user');
    }
}
