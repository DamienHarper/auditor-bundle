<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Enum;

use DH\AuditorBundle\Enum\EntitySort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class EntitySortTest extends TestCase
{
    #[DataProvider('provideFromQueryParamAcceptsEveryCaseCases')]
    public function testFromQueryParamAcceptsEveryCase(EntitySort $sort): void
    {
        $this->assertSame($sort, EntitySort::fromQueryParam($sort->value));
    }

    /**
     * @return iterable<string, array{EntitySort}>
     */
    public static function provideFromQueryParamAcceptsEveryCaseCases(): iterable
    {
        foreach (EntitySort::cases() as $sort) {
            yield $sort->value => [$sort];
        }
    }

    #[DataProvider('provideFromQueryParamFallsBackToNameAscendingCases')]
    public function testFromQueryParamFallsBackToNameAscending(?string $value): void
    {
        $this->assertSame(EntitySort::NameAsc, EntitySort::fromQueryParam($value));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function provideFromQueryParamFallsBackToNameAscendingCases(): iterable
    {
        yield 'missing' => [null];

        yield 'empty' => [''];

        yield 'wrong case' => ['NAME_DESC'];

        yield 'unknown' => ['created_at'];

        yield 'injection attempt' => ['name_desc; DROP TABLE author'];
    }
}
