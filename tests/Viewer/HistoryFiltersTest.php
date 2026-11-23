<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Viewer;

use DH\AuditorBundle\Viewer\HistoryFilters;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * @internal
 */
#[Small]
final class HistoryFiltersTest extends TestCase
{
    public function testDefaults(): void
    {
        $filters = HistoryFilters::fromRequest(new Request());

        $this->assertTrue($filters->attached, 'With the attached entities by default.');
        $this->assertSame([], $filters->toQuery());
        $this->assertFalse($filters->isFiltered());
    }

    public function testFromRequest(): void
    {
        $filters = HistoryFilters::fromRequest(new Request(['who' => '12', 'what' => 'update', 'field' => 'status', 'origin' => 'console', 'when' => '30', 'attached' => '0', 'on' => 'App\Entity\Post']));

        $this->assertFalse($filters->attached);
        $this->assertSame(['who' => '12', 'what' => 'update', 'field' => 'status', 'origin' => 'console', 'when' => '30', 'attached' => '0', 'on' => 'App\Entity\Post'], $filters->toQuery());
        $this->assertTrue($filters->isFiltered());
        $this->assertFalse(HistoryFilters::fromRequest(new Request(['attached' => '0']))->isFiltered(), 'Leaving out the attached entities is not a filter.');
    }
}
