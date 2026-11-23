<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

/**
 * Where a reference to an object leads, and whether the reader may follow it.
 */
final readonly class ObjectUrl
{
    public function __construct(
        public string $url,
        public bool $visible = true,
    ) {}
}
