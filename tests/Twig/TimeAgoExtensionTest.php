<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\AuditorBundle\Twig\TimeAgoExtension;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @internal
 */
#[Small]
final class TimeAgoExtensionTest extends TestCase
{
    public function testSecondsAgo(): void
    {
        $ext = $this->makeExtension();
        $date = new \DateTimeImmutable('-30 seconds');
        $result = $ext->timeAgo($date);

        $this->assertStringContainsString('time_ago.seconds_ago', $result);
    }

    public function testMinutesAgo(): void
    {
        $ext = $this->makeExtension();
        $date = new \DateTimeImmutable('-5 minutes');
        $result = $ext->timeAgo($date);

        $this->assertStringContainsString('time_ago.minutes_ago', $result);
    }

    public function testHoursAgo(): void
    {
        $ext = $this->makeExtension();
        $date = new \DateTimeImmutable('-3 hours');
        $result = $ext->timeAgo($date);

        $this->assertStringContainsString('time_ago.hours_ago', $result);
    }

    public function testDaysAgo(): void
    {
        $ext = $this->makeExtension();
        $date = new \DateTimeImmutable('-3 days');
        $result = $ext->timeAgo($date);

        $this->assertStringContainsString('time_ago.days_ago', $result);
    }

    /**
     * Dates older than one week must fall back to a locale-aware absolute date,
     * not the hardcoded US-style 'Y/m/d g:i:sa' format.
     *
     * @see https://github.com/DamienHarper/auditor-bundle/issues/359
     */
    public function testOlderThanOneWeekFallbackIsLocaleAware(): void
    {
        $enResult = $this->makeExtension('en_US')->timeAgo(new \DateTimeImmutable('-2 weeks'));
        $frResult = $this->makeExtension('fr_FR')->timeAgo(new \DateTimeImmutable('-2 weeks'));

        // Both must be non-empty strings (the formatter must produce output)
        $this->assertNotEmpty($enResult);
        $this->assertNotEmpty($frResult);

        // The formatted output must differ between locales — proving locale-awareness
        $this->assertNotSame($enResult, $frResult, 'Absolute date fallback must be locale-aware.');

        // Must NOT use the old hardcoded US format (slashes + am/pm suffix)
        $this->assertDoesNotMatchRegularExpression('/\d{4}\/\d{2}\/\d{2}/', $enResult);
        $this->assertDoesNotMatchRegularExpression('/\d{4}\/\d{2}\/\d{2}/', $frResult);
    }

    /**
     * Future dates must also use a locale-aware absolute date.
     *
     * @see https://github.com/DamienHarper/auditor-bundle/issues/359
     */
    public function testFutureDateFallbackIsLocaleAware(): void
    {
        $enResult = $this->makeExtension('en_US')->timeAgo(new \DateTimeImmutable('+1 day'));
        $frResult = $this->makeExtension('fr_FR')->timeAgo(new \DateTimeImmutable('+1 day'));

        $this->assertNotEmpty($enResult);
        $this->assertNotEmpty($frResult);
        $this->assertNotSame($enResult, $frResult, 'Future date fallback must be locale-aware.');
        $this->assertDoesNotMatchRegularExpression('/\d{4}\/\d{2}\/\d{2}/', $enResult);
        $this->assertDoesNotMatchRegularExpression('/\d{4}\/\d{2}\/\d{2}/', $frResult);
    }

    private function makeExtension(string $locale = 'en'): TimeAgoExtension
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('getLocale')->willReturn($locale);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params): string => $id.'|'.implode(',', $params)
        );

        return new TimeAgoExtension($translator);
    }
}
