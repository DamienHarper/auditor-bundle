<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TimeAgoExtension extends AbstractExtension
{
    private const int SECONDS_PER_MINUTE = 60;

    private const int SECONDS_PER_HOUR = 3_600;

    private const int SECONDS_PER_DAY = 86_400;

    private const int SECONDS_PER_WEEK = 604_800;

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('time_ago', $this->timeAgo(...)),
        ];
    }

    public function timeAgo(\DateTimeInterface $date): string
    {
        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 0) {
            // Future date, display as absolute date
            return $date->format('Y/m/d g:i:sa');
        }

        if ($diff < self::SECONDS_PER_MINUTE) {
            return $this->translator->trans('time_ago.seconds_ago', ['%count%' => $diff], 'auditor');
        }

        if ($diff < self::SECONDS_PER_HOUR) {
            $minutes = (int) floor($diff / self::SECONDS_PER_MINUTE);

            return $this->translator->trans('time_ago.minutes_ago', ['%count%' => $minutes], 'auditor');
        }

        if ($diff < self::SECONDS_PER_DAY) {
            $hours = (int) floor($diff / self::SECONDS_PER_HOUR);

            return $this->translator->trans('time_ago.hours_ago', ['%count%' => $hours], 'auditor');
        }

        if ($diff < self::SECONDS_PER_WEEK) {
            $days = (int) floor($diff / self::SECONDS_PER_DAY);

            return $this->translator->trans('time_ago.days_ago', ['%count%' => $days], 'auditor');
        }

        // More than a week ago, display as absolute date
        return $date->format('Y/m/d g:i:sa');
    }
}
