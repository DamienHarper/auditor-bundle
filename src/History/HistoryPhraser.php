<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\Contract\FieldLabelResolverInterface;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\History\Change;
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\History\ChangeKind;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\AuditorBundle\History\Part\ObjectReference;
use DH\AuditorBundle\History\Part\PhrasePart;
use DH\AuditorBundle\History\Part\TextPart;
use DH\AuditorBundle\History\Part\ValuePart;
use DH\AuditorBundle\Tests\History\HistoryPhraserTest;
use Psr\Container\ContainerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tells an action (a history item) as a phrase a reader understands, such as "changed Status: Draft → Published and
 * added to Tags: symfony and audit", and names its author.
 *
 * Each change, or group of changes, gives a fragment, in this order: the changes of the root before those of the
 * attached objects (Entry::$rootPath), then the order of the action, the fields of an entry in the order of their
 * mapping. A fragment comes from, in turn:
 * - a ChangeDescriberInterface service registered for the entity and the field, then for the entity (the changes the
 *   fragments of a describer list are not told again; between two describers listing the same change, the one telling
 *   the most changes wins);
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
 * "dh_auditor_fields" domain, translated with the number of objects of a summary, 1 otherwise; the short class name
 * without translation). They are inserted after the
 * translation, as parts of the fragment: {label}, the associated objects of {old} and {new} and the elements of
 * {names} as object references when their class and identifier are known, the other values (JSON paths included) as
 * values, {field} and {entity} as text.
 *
 * @see HistoryPhraserTest
 */
final readonly class HistoryPhraser
{
    public const string DOMAIN = 'dh_auditor_history';

    /**
     * Beyond this number of objects of the same entity changed the same way, an action tells how many, not which.
     */
    private const int BULK_THRESHOLD = 5;

    /**
     * Beyond this number of names, a list tells the first ones and how many others.
     */
    private const int MAX_NAMES = 5;

    private const string FIELDS_DOMAIN = TranslatedFieldLabelResolver::DOMAIN;

    /**
     * Marks of the parameters while a phrase is translated: the translation is then split on them (private use
     * characters, which no translation holds).
     */
    private const string OPEN = "\u{E000}";

    private const string CLOSE = "\u{E001}";

    /**
     * @param null|ContainerInterface $describers ChangeDescriberInterface services by "FQCN::field" or "FQCN"
     *
     * @internal the bundle builds this service: use it, do not build it
     */
    public function __construct(
        private TranslatorBagInterface&TranslatorInterface $translator,
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
            $described = $group['described'];
            $groupFragments = $described->fragments ?? [$this->fragment($group['changes'], $group['bulk'])];
            $toldChanges = array_merge(...array_map(static fn (Fragment $fragment): array => $fragment->changes, $groupFragments));

            if (null === $icon && $mainChange instanceof Change && \in_array($mainChange, $toldChanges, true)) {
                $icon = $described->icon ?? $groupFragments[0]->icon ?? null;
            }

            array_push($fragments, ...$groupFragments);
        }

        if ([] === $fragments) {
            $fragments[] = $this->unreadable($item);
        }

        return new Phrase($fragments, $icon ?? $fragments[0]->icon, $this->trans('list.separator'), $this->trans('list.and'));
    }

    /**
     * What an action was done on, for a list that mixes objects ("on Post “Why audit?”", "on 2 Post"): the object with
     * the most changes, or the number of objects when all the changes concern several objects of one entity, each
     * changed the same number of times. Null when the action only creates, snapshots or deletes objects (its phrase
     * names them), when it creates or deletes the object it would name, or when it concerns only the given object (in
     * the history of that object).
     */
    public function context(HistoryItem $item, ?string $rootClass = null, ?string $rootId = null): ?Phrase
    {
        $changes = $item->getChanges();
        // creations and deletions name their objects themselves
        if (array_all($changes, static fn (Change $change): bool => $change->kind->isEntityLevel())) {
            return null;
        }

        /** @var array<string, array{change: Change, count: int}> $objects */
        $objects = [];
        foreach ($changes as $change) {
            $key = $change->entity.'#'.$change->objectId;
            $objects[$key] ??= ['change' => $change, 'count' => 0];
            ++$objects[$key]['count'];
        }

        if (null !== $rootClass && [$rootClass.'#'.$rootId] === array_keys($objects)) {
            return null;
        }

        $entities = array_unique(array_map(static fn (array $object): string => $object['change']->entity, $objects));
        if (\count($objects) > 1 && 1 === \count($entities) && 1 === \count(array_unique(array_column($objects, 'count')))) {
            $entity = $changes[0]->entity;

            return new Phrase([new Fragment($this->message(['context.objects'], [
                'count' => \count($objects),
                'entity' => [new TextPart($this->entityName($entity, \count($objects)))],
            ]))]);
        }

        // the object with the most changes, the first one on a tie
        $main = $changes[0];
        $most = 0;
        foreach ($objects as $object) {
            if ($object['count'] > $most) {
                $main = $object['change'];
                $most = $object['count'];
            }
        }

        // an action that creates or deletes that object names it in its phrase
        if (array_any($changes, static fn (Change $change): bool => $change->kind->isEntityLevel() && $change->entity === $main->entity && $change->objectId === $main->objectId)) {
            return null;
        }

        return new Phrase([new Fragment($this->message(['context.object'], [
            'entity' => [new TextPart($this->entityName($main->entity, 1))],
            'label' => [new ObjectReference($main->entity, $main->objectId, self::objectLabel($main->objectLabel, $main->entity, $main->objectId))],
        ]))]);
    }

    /**
     * Puts first the fragments that tell changes of an object (the object a context names, the root of the history an
     * action is opened from), so that a shortened phrase starts with them.
     */
    public function focus(Phrase $phrase, string $class, string $id): Phrase
    {
        $concerns = static fn (Fragment $fragment): bool => array_any($fragment->changes, static fn (Change $change): bool => $change->entity === $class && $change->objectId === $id);
        $first = array_values(array_filter($phrase->fragments, $concerns));
        if ([] === $first) {
            return $phrase;
        }

        $others = array_values(array_filter($phrase->fragments, static fn (Fragment $fragment): bool => !$concerns($fragment)));

        return new Phrase([...$first, ...$others], $phrase->icon, $this->trans('list.separator'), $this->trans('list.and'));
    }

    /**
     * The object a context names, or null when it names a number of objects.
     */
    public static function contextObject(Phrase $context): ?ObjectReference
    {
        $reference = array_find($context->getParts(), static fn (PhrasePart $part): bool => $part instanceof ObjectReference);

        return $reference instanceof ObjectReference ? $reference : null;
    }

    /**
     * Keeps the first fragments of a phrase, followed by "and 4 other changes", the number of changes the other
     * fragments tell (a list of recent activity, a small screen).
     */
    public function shorten(Phrase $phrase, int $fragments): Phrase
    {
        $count = \count($phrase->fragments);
        if ($count <= $fragments) {
            return $phrase;
        }

        $kept = \array_slice($phrase->fragments, 0, $fragments);
        $others = array_merge(...array_map(static fn (Fragment $fragment): array => $fragment->changes, \array_slice($phrase->fragments, $fragments)));
        // the changes left out (a fragment may tell several: a summary, merged elements), or the fragments without any
        $kept[] = new Fragment([new TextPart($this->trans('more_changes', ['%count%' => \count($others) > 0 ? \count($others) : $count - $fragments]))], $others);

        return new Phrase($kept, $phrase->icon, $this->trans('list.separator'), $this->trans('list.and'));
    }

    /**
     * Name of the author of an action: the name recorded in the blame ("User 42" when only the id was recorded), or the
     * current one with an AuthorNameResolverInterface service ("Deleted user" when it returns null); without user,
     * "System", "Command line" or "Anonymous user".
     */
    public function author(HistoryItem $item): string
    {
        $userId = $item->userId;
        if (null !== $userId && '' !== $userId) {
            if ($this->authorNameResolver instanceof AuthorNameResolverInterface) {
                return $this->authorNameResolver->resolve($userId, $item) ?? $this->trans('author.deleted');
            }

            return null !== $item->username && '' !== $item->username ? $item->username : $this->trans('author.unknown', ['{id}' => (string) $userId]);
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
     * Name of an entity: key "FQCN" of the "dh_auditor_fields" domain, translated with $count; the short class name
     * without translation.
     */
    public function entityName(string $entity, int $count = 1): string
    {
        if ($this->has($entity, self::FIELDS_DOMAIN)) {
            return $this->translator->trans($entity, ['%count%' => $count], self::FIELDS_DOMAIN);
        }

        $short = mb_strrchr($entity, '\\');

        return false === $short ? $entity : mb_substr($short, 1);
    }

    /**
     * The fragment of an action whose entries hold no change the extractor can read (an empty diff, a format it does
     * not know): "changed Post “Why audit?”".
     */
    private function unreadable(HistoryItem $item): Fragment
    {
        $entry = $item->entries[0];
        $entity = ChangeExtractor::entityOf($entry);
        $source = $entry->getDiffSource() ?? [];
        $label = \is_string($source['label'] ?? null) ? $source['label'] : '#'.$entry->objectId;

        return new Fragment($this->message(['default.unknown'], [
            'entity' => [new TextPart($this->entityName($entity, 1))],
            'label' => [new ObjectReference($entity, $entry->objectId, ValueFormatter::truncate($label))],
        ]), [], 'edited');
    }

    /**
     * Changes of the item in the order of the phrase, grouped by fragment.
     *
     * A change a describer tells stays alone; the others are merged (collections) or summed up (bulk changes).
     *
     * @return list<array{changes: non-empty-list<Change>, bulk: ?int, described: ?Phrase}> bulk: number of objects of
     *                                                                                      a summary; described: the
     *                                                                                      phrase of a describer
     */
    private function groups(HistoryItem $item): array
    {
        $changes = $this->ordered($this->toldChanges($item->getChanges()));

        // a describer tells its change, and the other changes its fragments list. When two described phrases tell the
        // same change, the one telling the most changes is kept (the first one on a tie); the changes only the other one
        // told get their default fragments.
        $candidates = [];
        foreach ($changes as $index => $change) {
            $phrase = $this->describe($change, $item);
            if (!$phrase instanceof Phrase) {
                continue;
            }

            $fragments = array_map(static fn (Fragment $fragment): Fragment => [] === $fragment->changes ? new Fragment($fragment->parts, [$change], $fragment->icon) : $fragment, $phrase->fragments);
            $tells = [];
            foreach ($fragments as $fragment) {
                foreach ($fragment->changes as $toldChange) {
                    $tells[spl_object_id($toldChange)] = true;
                }
            }

            $candidates[$index] = ['phrase' => new Phrase($fragments, $phrase->icon), 'tells' => $tells];
        }

        $order = array_keys($candidates);
        usort($order, static fn (int $a, int $b): int => [\count($candidates[$b]['tells']), $a] <=> [\count($candidates[$a]['tells']), $b]);

        $described = [];
        $told = [];
        foreach ($order as $index) {
            if ([] !== array_intersect_key($candidates[$index]['tells'], $told)) {
                continue;
            }

            $described[$index] = $candidates[$index]['phrase'];
            $told += $candidates[$index]['tells'];
        }

        $changes = array_filter($changes, static fn (Change $change, int $index): bool => isset($described[$index]) || !isset($told[spl_object_id($change)]), ARRAY_FILTER_USE_BOTH);
        ksort($described);

        // more than BULK_THRESHOLD objects with the same entity, kind and field: one summary, where the first one was
        $objects = [];
        foreach ($changes as $index => $change) {
            if (!isset($described[$index])) {
                $objects[$this->bulkKey($change)][$change->objectId] = true;
            }
        }

        /** @var list<non-empty-list<Change>> $groups */
        $groups = [];

        /** @var list<?int> $bulks number of objects of each group that sums up a bulk change */
        $bulks = [];

        /** @var array<string, int> $bulkGroups group of each bulk key */
        $bulkGroups = [];

        /** @var array<int, Phrase> $phrases phrase of the groups of a described change */
        $phrases = [];
        foreach ($changes as $index => $change) {
            if (isset($described[$index])) {
                $phrases[\count($groups)] = $described[$index];
                $groups[] = [$change];
                $bulks[] = null;

                continue;
            }

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
            if ($last >= 0 && null === $bulks[$last] && !isset($phrases[$last]) && $this->merges($groups[$last][0], $change)) {
                $groups[$last][] = $change;

                continue;
            }

            $groups[] = [$change];
            $bulks[] = null;
        }

        $result = [];
        foreach ($groups as $index => $group) {
            $result[] = ['changes' => $group, 'bulk' => $bulks[$index], 'described' => $phrases[$index] ?? null];
        }

        return $result;
    }

    /**
     * The changes a phrase tells: adding to a collection an object the action creates, or removing from it an object
     * the action deletes, is part of that creation or deletion ("deleted Comment “spam”", not "deleted Comment “spam”
     * and removed from Comments: spam"). The details of the action still list them.
     *
     * @param list<Change> $changes
     *
     * @return list<Change>
     */
    private function toldChanges(array $changes): array
    {
        $created = [];
        $deleted = [];
        foreach ($changes as $change) {
            if (ChangeKind::Deleted === $change->kind) {
                $deleted[$change->entity.'#'.$change->objectId] = true;
            } elseif ($change->kind->isEntityLevel()) {
                $created[$change->entity.'#'.$change->objectId] = true;
            }
        }

        if ([] === $created && [] === $deleted) {
            return $changes;
        }

        return array_values(array_filter($changes, static function (Change $change) use ($created, $deleted): bool {
            $target = $change->getTargetClass().'#'.$change->getTargetId();

            return match ($change->kind) {
                ChangeKind::Added => !isset($created[$target]),
                ChangeKind::Removed => !isset($deleted[$target]),
                default => true,
            };
        }));
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
                throw new \LogicException(\sprintf('The change describer of %s is a %s, not a %s.', $id, get_debug_type($describer), ChangeDescriberInterface::class));
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

        return new Fragment($this->message($keys, $this->parameters($changes, $bulk)), $changes, $this->icon($change, $type, $longText));
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
     * Parameters of the phrase of a fragment: {count} as a number, the others as parts ({names} as one part per name,
     * joined later as "a, b and c").
     *
     * @param non-empty-list<Change> $changes
     *
     * @return array<string, int|list<PhrasePart>>
     */
    private function parameters(array $changes, ?int $bulk): array
    {
        $change = $changes[0];

        $parameters = [
            'count' => $bulk ?? \count($changes),
            'entity' => [new TextPart($this->entityName($change->entity, $bulk ?? 1))],
            'field' => [new TextPart(null === $change->field ? '' : $this->fieldLabelResolver->resolve($change->entity, $change->field))],
            'label' => [new ObjectReference($change->entity, $change->objectId, self::objectLabel($change->objectLabel, $change->entity, $change->objectId))],
            'old' => [],
            'new' => [],
            'names' => [],
        ];

        if (null !== $bulk || null === $change->field) {
            return $parameters;
        }

        switch ($change->kind) {
            case ChangeKind::Added:
            case ChangeKind::Removed:
                $parameters['names'] = array_map(static function (Change $element): PhrasePart {
                    $label = self::objectLabel($element->newLabel ?? $element->oldLabel, (string) $element->getTargetClass(), (string) $element->getTargetId());
                    $class = $element->getTargetClass();
                    $id = $element->getTargetId();

                    return null === $class || null === $id ? new ValuePart($label) : new ObjectReference($class, $id, $label);
                }, $changes);

                break;

            case ChangeKind::Json:
                $parameters['names'] = array_map(static fn (string $path): PhrasePart => new ValuePart($path), self::paths(\is_array($change->new) ? $change->new : []));

                break;

            default:
                $parameters['old'] = [$this->value($change, $change->old, $change->oldLabel)];
                $parameters['new'] = [$this->value($change, $change->new, $change->newLabel)];
        }

        return $parameters;
    }

    /**
     * A value of a field change: a reference when it is an associated object (an association summary holding its
     * class and identifier), a formatted value otherwise.
     */
    private function value(Change $change, mixed $value, ?string $label): PhrasePart
    {
        $formatted = ValueFormatter::truncate($this->values->format($change->entity, (string) $change->field, $value, $label));
        if (!\is_array($value) || !\is_string($value['class'] ?? null)) {
            return new ValuePart($formatted);
        }

        $key = \is_string($value['pkName'] ?? null) ? $value['pkName'] : 'id';
        $id = $value[$key] ?? null;

        return \is_int($id) || \is_string($id) ? new ObjectReference($value['class'], (string) $id, $formatted) : new ValuePart($formatted);
    }

    /**
     * Label of an object, cut for a phrase: "#id" without label, or for the label the provider writes for an object
     * without one ("FQCN#id").
     */
    private static function objectLabel(?string $label, string $class, string $id): string
    {
        return ValueFormatter::truncate(null === $label || '' === $label || $class.'#'.$id === $label ? '#'.$id : $label);
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
     * Translates the first defined key into parts: the parameters are replaced by marks, the translation is split on
     * them, and each mark gives way to the parts of its parameter.
     *
     * @param non-empty-list<string>              $keys
     * @param array<string, int|list<PhrasePart>> $parameters
     *
     * @return list<PhrasePart>
     */
    private function message(array $keys, array $parameters): array
    {
        $key = array_find($keys, fn (string $key): bool => $this->has($key)) ?? $keys[array_key_last($keys)];

        $marked = [];
        $inserted = [];
        foreach ($parameters as $name => $value) {
            // full forms ("{label}", "%count%"): an ICU message reads them as "label" and "count", a message without ICU
            // only replaces them, not every word holding their name
            if (\is_int($value)) {
                $marked['%'.$name.'%'] = $value;

                continue;
            }

            $marked['{'.$name.'}'] = self::OPEN.$name.self::CLOSE;
            $inserted[$name] = $this->list($value);
        }

        $pieces = preg_split('/'.self::OPEN.'(\w+)'.self::CLOSE.'/u', $this->translator->trans($key, $marked, self::DOMAIN), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        $parts = [];
        foreach ($pieces as $index => $piece) {
            if (1 === $index % 2) {
                array_push($parts, ...$inserted[$piece] ?? []);
            } else {
                $parts[] = new TextPart($piece);
            }
        }

        return Phrase::mergeText($parts);
    }

    /**
     * Joins parts as "a, b and c"; beyond MAX_NAMES, "a, b, c and 4 others".
     *
     * @param list<PhrasePart> $items
     *
     * @return list<PhrasePart>
     */
    private function list(array $items): array
    {
        if (\count($items) > self::MAX_NAMES) {
            $items = [...\array_slice($items, 0, 3), new TextPart($this->trans('list.others', ['%count%' => \count($items) - 3]))];
        }

        $parts = [];
        $last = \count($items) - 1;
        foreach ($items as $index => $item) {
            if ($index > 0) {
                $parts[] = new TextPart($this->trans($index === $last ? 'list.and' : 'list.separator'));
            }

            $parts[] = $item;
        }

        return $parts;
    }

    private function has(string $key, string $domain = self::DOMAIN): bool
    {
        $locale = $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : null;

        return $this->translator->getCatalogue($locale)->has($key, $domain);
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, self::DOMAIN);
    }
}
