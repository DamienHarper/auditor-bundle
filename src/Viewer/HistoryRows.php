<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Phrase;

/**
 * Rows of a list of actions, as History/_row.html.twig renders them: the phrase of each action and what it was done on
 * (its context), unless that is the object of the history or the phrase names it. The objects the phrases name are
 * checked for deletion in one query per class.
 */
final readonly class HistoryRows
{
    public function __construct(
        private HistoryPhraser $phraser,
        private ObjectExistence $existence,
    ) {}

    /**
     * @param list<HistoryItem>                $items
     * @param null|array{0: string, 1: string} $root  class and id of the history the actions are shown in: its own
     *                                                changes do not name it
     *
     * @return list<array{item: HistoryItem, phrase: Phrase, context: ?Phrase}>
     */
    public function build(array $items, ?array $root = null): array
    {
        $rows = [];
        $references = [];
        foreach ($items as $item) {
            $phrase = $this->phraser->phrase($item, $root[0] ?? null, $root[1] ?? null);
            $context = $this->phraser->context($item, $root[0] ?? null, $root[1] ?? null);
            $focus = $context instanceof Phrase ? HistoryPhraser::contextObject($context) : null;
            $row = ['item' => $item, 'phrase' => $focus instanceof ObjectReference ? $this->phraser->focus($phrase, $focus->class, $focus->id) : $phrase, 'context' => $context];
            foreach ([$row['phrase'], $row['context']] as $phrase) {
                foreach ($phrase?->getParts() ?? [] as $part) {
                    if ($part instanceof ObjectReference) {
                        $references[] = $part;
                    }
                }
            }

            $rows[] = $row;
        }

        $this->existence->prime($references);

        return $rows;
    }
}
