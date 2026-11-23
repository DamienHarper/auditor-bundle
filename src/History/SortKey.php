<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\AuditorBundle\Tests\History\SortKeyTest;

/**
 * Sort keys that compare with Entry::getSortKey(): an element of the application dated like an audit entry sorts
 * next to it.
 *
 * @see SortKeyTest
 */
final class SortKey
{
    private function __construct() {}

    /**
     * Sort key of an element dated $at; $discriminator orders elements of the same microsecond (an id, a class…).
     */
    public static function of(\DateTimeInterface $at, string $discriminator = ''): string
    {
        $utc = \DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC'));

        return \sprintf('%s %s', $utc->format('Y-m-d\TH:i:s.u'), $discriminator);
    }
}
