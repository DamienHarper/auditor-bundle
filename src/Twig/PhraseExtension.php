<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\ObjectUrl;
use DH\AuditorBundle\History\ObjectUrlResolverInterface;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\Tests\Twig\PhraseExtensionTest;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;
use Twig\Environment;

/**
 * History items told in templates:
 *
 *     {{ item|dh_auditor_author }} {{ item|dh_auditor_phrase }}
 *
 * |dh_auditor_phrase renders the phrase with @DHAuditor/History/phrase.html.twig (values in <strong>, object
 * references through @DHAuditor/History/object_reference.html.twig, linked when the reader may see them);
 * |dh_auditor_phrase_text gives it without markup.
 *
 * @see PhraseExtensionTest
 */
final readonly class PhraseExtension
{
    public function __construct(
        private HistoryPhraser $phraser,
        private ?ObjectUrlResolverInterface $urlResolver = null,
    ) {}

    #[AsTwigFilter('dh_auditor_phrase', needsEnvironment: true, isSafe: ['html'])]
    public function phrase(Environment $twig, HistoryItem|Phrase $phrase): string
    {
        return $twig->render('@DHAuditor/History/phrase.html.twig', [
            'phrase' => $phrase instanceof Phrase ? $phrase : $this->phraser->phrase($phrase),
        ]);
    }

    #[AsTwigFilter('dh_auditor_phrase_text')]
    public function text(HistoryItem|Phrase $phrase): string
    {
        return (string) ($phrase instanceof Phrase ? $phrase : $this->phraser->phrase($phrase));
    }

    #[AsTwigFilter('dh_auditor_author')]
    public function author(HistoryItem $item): string
    {
        return $this->phraser->author($item);
    }

    /**
     * Where an object reference leads, or null (always null without resolver: no Doctrine provider).
     */
    #[AsTwigFunction('dh_auditor_object_url')]
    public function objectUrl(ObjectReference $reference): ?ObjectUrl
    {
        return $this->urlResolver?->resolve($reference);
    }
}
