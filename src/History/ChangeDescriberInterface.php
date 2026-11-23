<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\History\Change;
use DH\Auditor\History\HistoryItem;
use DH\AuditorBundle\Attribute\AsChangeDescriber;

/**
 * Tells a change in the words of the application, when a translated phrase is not enough: the describer reads the
 * whole action (the other changes, the raw entries) through the history item.
 *
 * Register it with #[AsChangeDescriber(entity: Post::class, field: 'status')]; without field, it describes every change
 * of the entity that no field describer takes, the creation and the deletion included.
 *
 * @see AsChangeDescriber
 */
interface ChangeDescriberInterface
{
    /**
     * @return null|Phrase the fragments telling the change, or null for the default phrase
     */
    public function describe(Change $change, HistoryItem $item): ?Phrase;
}
