<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Tests\Twig\DiffExtensionTest;
use DH\AuditorBundle\Viewer\WordDiff;
use Twig\Attribute\AsTwigFunction;

/**
 * @see DiffExtensionTest
 */
final readonly class DiffExtension
{
    /**
     * Tells whether a node found inside a JSON deep diff is a leaf ({new}, {old} or {new, old}).
     * The root of a changeset is not a node: use Entry::isDeepDiff() to read it.
     */
    #[AsTwigFunction('dh_auditor_is_diff_leaf')]
    public function isDiffLeaf(mixed $node): bool
    {
        return \is_array($node) && Entry::isDiffLeaf($node);
    }

    /**
     * Word by word comparison of two texts, as runs of [kind, text] (kind: equal, delete, insert); null when they are
     * not both texts, or too long to compare (see WordDiff).
     *
     * @return null|list<array{0: 'delete'|'equal'|'insert', 1: string}>
     */
    #[AsTwigFunction('dh_auditor_word_diff')]
    public function wordDiff(mixed $old, mixed $new): ?array
    {
        return (\is_string($old) || null === $old) && \is_string($new) ? WordDiff::diff($old ?? '', $new) : null;
    }
}
