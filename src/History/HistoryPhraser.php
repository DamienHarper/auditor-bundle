<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\Contract\FieldLabelResolverInterface;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\History\Change;
use DH\Auditor\History\ChangeKind;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\AuditorBundle\Tests\History\HistoryPhraserTest;
use Psr\Container\ContainerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells an action (a history item) as a phrase a reader understands, such as "changed Status: Draft → Published and
 * added to Tags: symfony and audit", and names its author.
 *
 * Each change gives a fragment, in this order: the changes of the root before those of the attached objects
 * (Entry::$rootPath), then the order of the action, the fields of an entry in the order of their mapping. A fragment
 * comes from, in turn:
 * - a ChangeDescriberInterface service registered for the entity and the field, then for the entity;
 * - the phrase of the application in the "dh_auditor_history" domain (ICU), keyed "FQCN.kind" for the creation,
 *   snapshot and deletion ("App\Entity\Post.created") and "FQCN.field.kind" for a field ("App\Entity\Post.status.changed");
 * - the neutral phrase of the bundle ("default.kind").
 *
 * Consecutive Added (or Removed) changes of the same field of the same object make one fragment; the changes of more
 * than 5 objects of the same entity, kind and field make one summary ("deleted 38 articles", key "FQCN.kind.bulk" or
 * "FQCN.field.kind.bulk").
 *
 * The phrases get these ICU parameters: {label} (the object label, or "#id"), {old}, {new} (formatted values), {names}
 * (collection elements, or the changed paths of a JSON field, as "a, b and c"), {count} (number of elements or
 * objects), {field} (field label, "dh_auditor_fields" domain), {entity} (entity name: key "FQCN" of the
 * "dh_auditor_fields" domain, given {count}; the short class name otherwise).
 *
 * @see HistoryPhraserTest
 */
final readonly class HistoryPhraser
{
    public const string DOMAIN = 'dh_auditor_history';

    /**
     * Beyond this number of objects of the same entity changed the same way, an action tells how many, not which.
     */
    public const int BULK_THRESHOLD = 5;

    /**
     * Beyond this number of names, a list tells the first ones and how many others.
     */
    public const int MAX_NAMES = 5;

    private const string FIELDS_DOMAIN = TranslatedFieldLabelResolver::DOMAIN;

    /**
     * Values of the parameters are wrapped in these marks while the HTML phrase is translated, then escaped and put in
     * <strong> (private use characters: no translation holds them).
     */
    private const string OPEN = "\u{E000}";

    private const string CLOSE = "\u{E001}";

    /**
     * @param null|ContainerInterface $describers ChangeDescriberInterface services by "FQCN::field" or "FQCN"
     */
    public function __construct(
        private TranslatorInterface $translator,
        private FieldLabelResolverInterface $fieldLabelResolver,
        private ValueFormatter $values,
        private ?ContainerInterface $describers = null,
        private ?AuthorNameResolverInterface $authorNameResolver = null,
    ) {}

    public function phrase(HistoryItem $item): Phrase
    {
        $fragments = [];
        $mainChange = $item->getMainChange();
        $icon = null;

        foreach ($this->groups($item) as $group) {
            $described = 1 === \count($group['changes']) && null === $group['bulk'] ? $this->describe($group['changes'][0], $item) : null;
            $groupFragments = $described->fragments ?? [$this->fragment($group['changes'], $group['bulk'])];

            if (null === $icon && $mainChange instanceof Change && \in_array($mainChange, $group['changes'], true)) {
                $icon = $described->icon ?? $groupFragments[0]->icon ?? null;
            }

            array_push($fragments, ...$groupFragments);
        }

        return new Phrase($fragments, $icon ?? $fragments[0]->icon ?? null, $this->trans('list.separator'), $this->trans('list.and'));
    }

    /**
     * Name of the author of an action: the name recorded in the blame (or the current one, with an
     * AuthorNameResolverInterface service); without user, "System", "Command line" or "Anonymous user".
     */
    public function author(HistoryItem $item): string
    {
        $userId = $item->userId;
        if (null !== $userId && '' !== $userId) {
            if ($this->authorNameResolver instanceof AuthorNameResolverInterface) {
                return $this->authorNameResolver->resolve($userId, $item) ?? $this->trans('author.deleted');
            }

            return $item->username ?? $this->trans('author.unknown', ['id' => (string) $userId]);
        }

        if (null !== $item->username && '' !== $item->username) {
            return $item->username;
        }

        return match ($item->origin) {
            BlameOrigin::Console => $this->trans('author.console'),
            BlameOrigin::Http => $this->trans('author.anonymous'),
            default => $this->trans('author.system'),
        };
    }

    /**
     * Changes of the item in the order of the phrase, grouped by fragment.
     *
     * @return list<array{changes: non-empty-list<Change>, bulk: ?int}> bulk: number of objects of a summary
     */
    private function groups(HistoryItem $item): array
    {
        $changes = $this->ordered($item->getChanges());

        // more than BULK_THRESHOLD objects with the same entity, kind and field: one summary, where the first one was
        $objects = [];
        foreach ($changes as $change) {
            $objects[$this->bulkKey($change)][$change->objectId] = true;
        }

        /** @var list<non-empty-list<Change>> $groups */
        $groups = [];

        /** @var list<?int> $bulks number of objects of each group that sums up a bulk change */
        $bulks = [];

        /** @var array<string, int> $bulkGroups group of each bulk key */
        $bulkGroups = [];
        foreach ($changes as $change) {
            $key = $this->bulkKey($change);
            if (\count($objects[$key]) > self::BULK_THRESHOLD) {
                if (isset($bulkGroups[$key])) {
                    $groups[$bulkGroups[$key]][] = $change;
                } else {
                    $bulkGroups[$key] = \count($groups);
                    $groups[] = [$change];
                    $bulks[] = \count($objects[$key]);
                }

                continue;
            }

            $last = \count($groups) - 1;
            if ($last >= 0 && null === $bulks[$last] && $this->merges($groups[$last][0], $change)) {
                $groups[$last][] = $change;

                continue;
            }

            $groups[] = [$change];
            $bulks[] = null;
        }

        return array_map(static fn (array $group, ?int $bulk): array => ['changes' => $group, 'bulk' => $bulk], $groups, $bulks);
    }

    /**
     * Changes of the root first, then in the order of the item; the fields of an entry in the order of their mapping.
     *
     * @param list<Change> $changes
     *
     * @return list<Change>
     */
    private function ordered(array $changes): array
    {
        $entries = [];
        $sortKeys = [];
        foreach ($changes as $index => $change) {
            $entries[spl_object_id($change->entry)] ??= \count($entries);
            $sortKeys[$index] = [
                null === $change->entry->rootPath ? 0 : 1,
                $entries[spl_object_id($change->entry)],
                null === $change->field ? -1 : $this->values->position($change->entity, $change->field),
                $index,
            ];
        }

        $order = array_keys($changes);
        usort($order, static fn (int $a, int $b): int => $sortKeys[$a] <=> $sortKeys[$b]);

        return array_map(static fn (int $index): Change => $changes[$index], $order);
    }

    private function bulkKey(Change $change): string
    {
        return $change->entity.'|'.$change->kind->value.'|'.$change->field;
    }

    /**
     * Tells whether a change joins the fragment of another one: elements added to (or removed from) the same
     * collection of the same object.
     */
    private function merges(Change $first, Change $change): bool
    {
        return \in_array($change->kind, [ChangeKind::Added, ChangeKind::Removed], true)
            && $first->kind === $change->kind
            && $first->entity === $change->entity
            && $first->objectId === $change->objectId
            && $first->field === $change->field;
    }

    private function describe(Change $change, HistoryItem $item): ?Phrase
    {
        if (!$this->describers instanceof ContainerInterface) {
            return null;
        }

        $ids = null === $change->field ? [$change->entity] : [$change->entity.'::'.$change->field, $change->entity];
        foreach ($ids as $id) {
            if (!$this->describers->has($id)) {
                continue;
            }

            $describer = $this->describers->get($id);
            if (!$describer instanceof ChangeDescriberInterface) {
                continue;
            }

            $phrase = $describer->describe($change, $item);
            if ($phrase instanceof Phrase && !$phrase->isEmpty()) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * @param non-empty-list<Change> $changes
     */
    private function fragment(array $changes, ?int $bulk): Fragment
    {
        $change = $changes[0];
        $type = null === $change->field ? null : $this->values->typeOf($change->entity, $change->field);
        $longText = $this->isLongText($change, $type);

        $kind = $change->kind->value;
        $keys = null === $change->field ? [$change->entity.'.'.$kind] : [$change->entity.'.'.$change->field.'.'.$kind];
        if (null !== $bulk) {
            $keys = [$keys[0].'.bulk', 'default.bulk.'.$kind];
        } else {
            $keys[] = 'default.'.($longText && \in_array($change->kind, [ChangeKind::Set, ChangeKind::Changed], true) ? 'edited' : $kind);
        }

        [$text, $html] = $this->message($keys, $this->parameters($changes, $bulk));

        return new Fragment($text, $changes, $this->icon($change, $type, $longText), $html);
    }

    private function isLongText(Change $change, ?string $type): bool
    {
        if ('text' === $type) {
            return true;
        }

        return array_any([$change->old, $change->new], static fn ($value): bool => \is_string($value) && mb_strlen($value) > ValueFormatter::MAX_LENGTH);
    }

    private function icon(Change $change, ?string $type, bool $longText): string
    {
        if (!\in_array($change->kind, [ChangeKind::Set, ChangeKind::Changed, ChangeKind::Cleared], true)) {
            return $change->kind->value;
        }

        return match (true) {
            'enum' === $type => 'status',
            'boolean' === $type || \is_bool($change->old) || \is_bool($change->new) => 'toggle',
            $longText => 'text',
            default => 'edited',
        };
    }

    /**
     * Parameters of the phrase of a fragment: plain values, and values wrapped for the HTML phrase.
     *
     * @param non-empty-list<Change> $changes
     *
     * @return array<string, array{0: int|string, 1: bool}> name => [value, is a value to highlight]
     */
    private function parameters(array $changes, ?int $bulk): array
    {
        $change = $changes[0];
        $count = $bulk ?? \count($changes);

        $parameters = [
            'count' => [$count, false],
            'entity' => [$this->entityName($change->entity, $bulk ?? 1), false],
            'field' => [null === $change->field ? '' : $this->fieldLabelResolver->resolve($change->entity, $change->field), false],
            'label' => [ValueFormatter::truncate($change->objectLabel ?? '#'.$change->objectId), true],
            'old' => ['', true],
            'new' => ['', true],
            'names' => ['', true],
        ];

        if (null !== $bulk || null === $change->field) {
            return $parameters;
        }

        switch ($change->kind) {
            case ChangeKind::Added:
            case ChangeKind::Removed:
                $names = array_map(static fn (Change $element): string => ValueFormatter::truncate($element->newLabel ?? $element->oldLabel ?? '#'.$element->getTargetId()), $changes);
                $parameters['names'] = [implode(self::OPEN, $names), true];

                break;

            case ChangeKind::Json:
                $parameters['names'] = [implode(self::OPEN, self::paths(\is_array($change->new) ? $change->new : [])), true];

                break;

            default:
                $parameters['old'] = [ValueFormatter::truncate($this->values->format($change->entity, $change->field, $change->old, $change->oldLabel)), true];
                $parameters['new'] = [ValueFormatter::truncate($this->values->format($change->entity, $change->field, $change->new, $change->newLabel)), true];
        }

        return $parameters;
    }

    /**
     * Paths of the changed leaves of a JSON deep diff ("seo.description").
     *
     * @param array<array-key, mixed> $node
     *
     * @return list<string>
     */
    private static function paths(array $node, string $prefix = ''): array
    {
        $paths = [];
        foreach ($node as $key => $child) {
            if (!\is_array($child)) {
                continue;
            }

            $path = $prefix.$key;
            if (Entry::isDiffLeaf($child)) {
                $paths[] = $path;

                continue;
            }

            array_push($paths, ...self::paths($child, $path.'.'));
        }

        return $paths;
    }

    /**
     * Translates the first defined key, as text and as HTML.
     *
     * @param non-empty-list<string>                       $keys
     * @param array<string, array{0: int|string, 1: bool}> $parameters
     *
     * @return array{0: string, 1: string}
     */
    private function message(array $keys, array $parameters): array
    {
        $key = array_find($keys, fn (string $key): bool => $this->has($key)) ?? $keys[array_key_last($keys)];

        $text = [];
        $marked = [];
        $values = [];
        foreach ($parameters as $name => [$value, $highlight]) {
            if (!\is_string($value) || !$highlight) {
                $text[$name] = $value;
                $marked[$name] = $value;

                continue;
            }

            // a list of names is held as names separated by OPEN: joined as "a, b and c"
            $items = '' === $value ? [] : explode(self::OPEN, $value);
            $text[$name] = $this->list($items);
            $marked[$name] = $this->list(array_map(static function (string $item) use (&$values): string {
                $values[] = $item;

                return self::OPEN.(\count($values) - 1).self::CLOSE;
            }, $items));
        }

        $html = htmlspecialchars($this->translator->trans($key, $marked, self::DOMAIN), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = (string) preg_replace_callback('/'.self::OPEN.'(\d+)'.self::CLOSE.'/u', static fn (array $match): string => '<strong>'.htmlspecialchars($values[(int) $match[1]] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</strong>', $html);

        return [$this->translator->trans($key, $text, self::DOMAIN), $html];
    }

    /**
     * Joins names as "a, b and c"; beyond MAX_NAMES, "a, b, c and 4 others".
     *
     * @param list<string> $items
     */
    private function list(array $items): string
    {
        if (\count($items) > self::MAX_NAMES) {
            $others = $this->trans('list.others', ['count' => \count($items) - 3]);
            $items = [...\array_slice($items, 0, 3), $others];
        }

        $last = array_pop($items);
        if (null === $last) {
            return '';
        }

        return [] === $items ? $last : implode($this->trans('list.separator'), $items).$this->trans('list.and').$last;
    }

    private function entityName(string $entity, int $count): string
    {
        if ($this->has($entity, self::FIELDS_DOMAIN)) {
            return $this->translator->trans($entity, ['count' => $count], self::FIELDS_DOMAIN);
        }

        $short = mb_strrchr($entity, '\\');

        return false === $short ? $entity : mb_substr($short, 1);
    }

    private function has(string $key, string $domain = self::DOMAIN): bool
    {
        if ($this->translator instanceof TranslatorBagInterface) {
            $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : null;

            return $this->translator->getCatalogue($locale)->has($key, $domain);
        }

        return $this->translator->trans($key, [], $domain) !== $key;
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN);
    }
}
