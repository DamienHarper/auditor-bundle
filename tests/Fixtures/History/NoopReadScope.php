<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

final class NoopReadScope
{
    public function __invoke(): void {}
}
