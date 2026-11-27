<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * User who dispatched a message, blamed for the changes made while the message is handled by a worker: its
 * identifier, name and class, and the user who impersonated it, if any.
 *
 * Added by BlameContextMiddleware when the message is dispatched.
 */
final readonly class BlameStamp implements StampInterface
{
    public function __construct(
        public ?string $userId,
        public ?string $username,
        public ?string $fqdn = null,
        public ?string $impersonatorId = null,
        public ?string $impersonatorUsername = null,
        public ?string $impersonatorFqdn = null,
    ) {}
}
