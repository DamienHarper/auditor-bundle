<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Tests\Twig\DiffExtensionTest;
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
}
