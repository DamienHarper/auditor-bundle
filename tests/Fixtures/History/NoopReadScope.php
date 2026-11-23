<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;

final class NoopReadScope
{
    public function __invoke(Query $query, string $entity): void {}
}
