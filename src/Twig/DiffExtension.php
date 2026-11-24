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
     * Share of the longest text that the change keeps, from which the changed words are shown in place.
     */
    private const float KEPT_INLINE = 0.4;

    /**
     * Length up to which two values whose words mostly changed are shown as old → new rather than one above the other.
     */
    private const int SHORT = 40;

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
     * Word by word comparison of two texts, as runs of [kind, text] (kind: equal, delete, insert); null when the new
     * value is not a text, the old one neither a text nor null, or when they are too long to compare (see WordDiff).
     *
     * @return null|list<array{0: 'delete'|'equal'|'insert', 1: string}>
     */
    #[AsTwigFunction('dh_auditor_word_diff')]
    public function wordDiff(mixed $old, mixed $new): ?array
    {
        return (\is_string($old) || null === $old) && \is_string($new) ? WordDiff::diff($old ?? '', $new) : null;
    }

    /**
     * A value of the action details as it is shown: a text as it is, between quotes only when it would otherwise be
     * misread (see isAmbiguousText()), null as "null", any other value as JSON.
     */
    #[AsTwigFunction('dh_auditor_value_text')]
    public function valueText(mixed $value): string
    {
        if (\is_string($value)) {
            return $this->isAmbiguousText($value) ? '"'.$value.'"' : $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Tells whether a text shown without quotes would be misread: empty, starting or ending with a white space, or
     * looking like a number, a boolean or null.
     */
    #[AsTwigFunction('dh_auditor_is_ambiguous_text')]
    public function isAmbiguousText(string $text): bool
    {
        return '' === $text
            || mb_trim($text) !== $text
            || is_numeric($text)
            || \in_array(mb_strtolower($text), ['true', 'false', 'null'], true);
    }

    /**
     * How to show the change of a text into another one, with the runs of their word diff:
     * - inline: most of the text is kept, the changed words are shown in place;
     * - replace: short values whose words mostly changed, shown as old → new;
     * - stack: a longer text rewritten, the old text shown above the new one.
     *
     * Null when one of the values is not a text (null included) or when they are too long to compare.
     *
     * @return null|array{mode: 'inline'|'replace'|'stack', runs: list<array{0: 'delete'|'equal'|'insert', 1: string}>}
     */
    #[AsTwigFunction('dh_auditor_text_change')]
    public function textChange(mixed $old, mixed $new): ?array
    {
        if (!\is_string($old) || !\is_string($new)) {
            return null;
        }

        $runs = WordDiff::diff($old, $new);
        if (null === $runs) {
            return null;
        }

        $kept = 0;
        foreach ($runs as [$kind, $text]) {
            if ('equal' === $kind) {
                $kept += mb_strlen(mb_trim($text));
            }
        }

        if ($kept / max(1, mb_strlen($old), mb_strlen($new)) >= self::KEPT_INLINE) {
            $mode = 'inline';
        } elseif (mb_strlen($old) <= self::SHORT && mb_strlen($new) <= self::SHORT) {
            $mode = 'replace';
        } else {
            $mode = 'stack';
        }

        return ['mode' => $mode, 'runs' => $runs];
    }
}
