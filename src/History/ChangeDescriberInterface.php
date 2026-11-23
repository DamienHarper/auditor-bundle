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
 * of the entity that no field describer tells (a field describer returning null hands the change to it), the creation
 * and the deletion included.
 *
 * A fragment tells the changes it lists, the described one when it lists none; the changes listed are not told again.
 * Between two describers listing the same change, the one telling the most changes wins.
 *
 * #[AsChangeDescriber] works on autoconfigured services; otherwise tag the service "dh_auditor.change_describer" with
 * the "entity" and "field" attributes.
 *
 * @see AsChangeDescriber
 */
interface ChangeDescriberInterface
{
    /**
     * @return null|Phrase the fragments telling the change, or null (or an empty phrase) to hand it on: to the describer
     *                     of the entity, then to the translated or default phrase
     */
    public function describe(Change $change, HistoryItem $item): ?Phrase;
}
