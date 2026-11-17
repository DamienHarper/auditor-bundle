<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Enum;

/**
 * Sort options for the "Audited Entities" overview page.
 */
enum EntitySort: string
{
    case NameAsc = 'name_asc';
    case NameDesc = 'name_desc';
    case CountDesc = 'count_desc';
    case CountAsc = 'count_asc';
    case ActivityDesc = 'activity_desc';
    case ActivityAsc = 'activity_asc';

    public static function fromQueryParam(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::NameAsc;
    }
}
