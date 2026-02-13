<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Filter;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\FilterInterface;

/**
 * Filter for NULL values (e.g., anonymous users where blame_id IS NULL).
 */
final readonly class NullFilter implements FilterInterface
{
    public function __construct(private string $name) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getSQL(): array
    {
        return [
            'sql' => \sprintf('%s IS NULL', $this->name),
            'params' => [],
        ];
    }
}
