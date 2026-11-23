<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use DH\Auditor\History\Change;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\AuditorBundle\Attribute\AsChangeDescriber;
use DH\AuditorBundle\History\ChangeDescriberInterface;
use DH\AuditorBundle\History\Phrase;

#[AsChangeDescriber(entity: Post::class, field: 'title')]
final class PostTitleDescriber implements ChangeDescriberInterface
{
    public function describe(Change $change, HistoryItem $item): Phrase
    {
        return Phrase::of('retitled the post');
    }
}
