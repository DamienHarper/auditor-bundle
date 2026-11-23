<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\Tests\Twig\PhraseExtensionTest;
use Twig\Attribute\AsTwigFilter;

/**
 * History items told in templates: {{ item|dh_auditor_author }} {{ item|dh_auditor_phrase }}.
 *
 * The phrase prints as escaped text; its HTML version, with the values in <strong>, is (item|dh_auditor_phrase).html.
 *
 * @see PhraseExtensionTest
 */
final readonly class PhraseExtension
{
    public function __construct(private HistoryPhraser $phraser) {}

    #[AsTwigFilter('dh_auditor_phrase')]
    public function phrase(HistoryItem $item): Phrase
    {
        return $this->phraser->phrase($item);
    }

    #[AsTwigFilter('dh_auditor_author')]
    public function author(HistoryItem $item): string
    {
        return $this->phraser->author($item);
    }
}
