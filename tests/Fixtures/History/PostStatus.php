<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum PostStatus: string implements TranslatableInterface
{
    case Draft = 'draft';
    case Published = 'published';

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return match ($this) {
            self::Draft => 'fr' === $locale ? 'Brouillon' : 'Draft',
            self::Published => 'fr' === $locale ? 'Publié' : 'Published',
        };
    }
}
