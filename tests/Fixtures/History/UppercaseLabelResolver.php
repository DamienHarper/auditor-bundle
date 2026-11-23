<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\Contract\EntityLabelResolverInterface;

final class UppercaseLabelResolver implements EntityLabelResolverInterface
{
    public function __invoke(object $entity): ?string
    {
        return mb_strtoupper($entity::class);
    }
}
