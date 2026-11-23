<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\AuditorBundle\History\Fragment;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\ObjectUrl;
use DH\AuditorBundle\History\ObjectUrlResolverInterface;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\Tests\Twig\PhraseExtensionTest;
use DH\AuditorBundle\Viewer\ObjectExistence;
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
        private ?ObjectExistence $existence = null,
    ) {}

    #[AsTwigFilter('dh_auditor_phrase', needsEnvironment: true, isSafe: ['html'])]
    public function phrase(Environment $twig, Fragment|HistoryItem|Phrase $phrase): string
    {
        return $twig->render('@DHAuditor/History/phrase.html.twig', ['phrase' => $this->toPhrase($phrase)]);
    }

    #[AsTwigFilter('dh_auditor_phrase_text')]
    public function text(Fragment|HistoryItem|Phrase $phrase): string
    {
        return (string) $this->toPhrase($phrase);
    }

    /**
     * The first fragments of a phrase, followed by "and 4 other changes".
     */
    #[AsTwigFilter('dh_auditor_shorten')]
    public function shorten(HistoryItem|Phrase $phrase, int $fragments): Phrase
    {
        return $this->phraser->shorten($this->toPhrase($phrase), $fragments);
    }

    /**
     * What an action was done on ("on Post “Why audit?”"), or null; see HistoryPhraser::context().
     */
    #[AsTwigFunction('dh_auditor_context')]
    public function context(HistoryItem $item, ?string $rootClass = null, int|string|null $rootId = null): ?Phrase
    {
        return $this->phraser->context($item, $rootClass, null === $rootId ? null : (string) $rootId);
    }

    /**
     * Name of an entity, from the "dh_auditor_fields" domain (key FQCN), or its short class name.
     */
    #[AsTwigFunction('dh_auditor_entity_name')]
    public function entityName(string $entity, int $count = 1): string
    {
        return $this->phraser->entityName($entity, $count);
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

    /**
     * Tells whether the object of a reference no longer exists, as far as the page checked it (see ObjectExistence).
     */
    #[AsTwigFunction('dh_auditor_is_deleted')]
    public function isDeleted(ObjectReference $reference): bool
    {
        return $this->existence?->isDeleted($reference) ?? false;
    }

    /**
     * The action of a single entry, to tell its author or its phrase.
     */
    #[AsTwigFunction('dh_auditor_history_item')]
    public function historyItem(Entry $entry): HistoryItem
    {
        return new HistoryItem((string) ($entry->transactionId ?? $entry->id), [$entry]);
    }

    private function toPhrase(Fragment|HistoryItem|Phrase $phrase): Phrase
    {
        return match (true) {
            $phrase instanceof Phrase => $phrase,
            $phrase instanceof Fragment => new Phrase([$phrase], $phrase->icon),
            default => $this->phraser->phrase($phrase),
        };
    }
}
