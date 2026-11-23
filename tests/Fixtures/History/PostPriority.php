<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\History;

use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

enum PostPriority: int implements TranslatableInterface
{
    case Low = 1;
    case High = 2;

    public function trans(TranslatorInterface $translator, ?string $locale = null): string
    {
        return $this->name;
    }
}
