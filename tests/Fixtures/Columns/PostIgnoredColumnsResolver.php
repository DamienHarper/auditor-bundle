<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\Columns;

use DH\Auditor\Provider\Doctrine\Auditing\IgnoredColumnsResolverInterface;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;

final class PostIgnoredColumnsResolver implements IgnoredColumnsResolverInterface
{
    public function __invoke(object $entity): array
    {
        return $entity instanceof Post && 'Secret' === $entity->getTitle() ? ['title'] : [];
    }
}
