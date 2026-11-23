<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;

final class PostRootResolver
{
    /**
     * @return array<string, ?object>
     */
    public function __invoke(object $entity): array
    {
        return $entity instanceof Comment ? ['commented_post' => $entity->getPost()] : [];
    }
}
