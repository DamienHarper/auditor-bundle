<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

enum PostKind: string
{
    case Article = 'article';
    case News = 'news';
}
