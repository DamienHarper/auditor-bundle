<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Messenger;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * User who dispatched a message, blamed for the changes made while the message is handled by a worker.
 *
 * Added by BlameContextMiddleware when the message is dispatched.
 */
final readonly class BlameStamp implements StampInterface
{
    public function __construct(
        public ?string $userId,
        public ?string $username,
    ) {}
}
