<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\NullFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\RootPage;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\RootQuery;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;

/**
 * Reads the history of an object for the viewer: the object and, unless left out, the entities attached to it
 * (#[AuditRoot], roots option), one page of actions at a time.
 *
 * @see ViewerControllerTest
 */
final readonly class ObjectHistory
{
    /**
     * Entries read at most to list the authors of a history.
     */
    public const int MAX_AUTHOR_ENTRIES = 2_000;

    public function __construct(
        private Reader $reader,
        private ?ActivityGraphProvider $cache = null,
    ) {}

    /**
     * The history, filtered.
     */
    public function query(string $class, string $id, HistoryFilters $filters, ?\DateTimeZone $timezone = null): RootQuery
    {
        $options = [
            'type' => $filters->what,
            'changed_field' => $filters->field,
            'blame_origin' => $filters->origin,
            'blame_id' => null !== $filters->who && HistoryFilters::ANONYMOUS !== $filters->who ? $filters->who : null,
        ];
        if (!$filters->attached) {
            $options['include'] = [$class];
        }

        $query = $this->reader->createRootQuery($class, $id, array_filter($options, static fn (mixed $value): bool => null !== $value));
        if (HistoryFilters::ANONYMOUS === $filters->who) {
            $query->addFilter(new NullFilter(Query::USER_ID));
        }

        if (null !== $filters->days) {
            $since = new \DateTimeImmutable('today', $timezone)->modify(\sprintf('-%d days', $filters->days - 1));
            $query->addFilter(new DateRangeFilter(Query::CREATED_AT, $since));
        }

        return $query;
    }

    public function page(string $class, string $id, HistoryFilters $filters, ?string $after, int $limit, ?\DateTimeZone $timezone = null): RootPage
    {
        return $this->query($class, $id, $filters, $timezone)->fetch($limit, $after);
    }

    /**
     * Number of entries of the history, attached entities included or not.
     */
    public function count(string $class, string $id, bool $attached): int
    {
        return $this->query($class, $id, new HistoryFilters(attached: $attached))->count();
    }

    /**
     * Authors of the history (the most recent MAX_AUTHOR_ENTRIES entries), by user id, under their latest name.
     *
     * @return array{users: list<array{id: string, name: string}>, anonymous: bool}
     */
    public function authors(string $class, string $id, bool $attached): array
    {
        $compute = function () use ($class, $id, $attached): array {
            $users = [];
            $anonymous = false;
            $read = 0;
            foreach ($this->query($class, $id, new HistoryFilters(attached: $attached))->iterate() as $entry) {
                if (++$read > self::MAX_AUTHOR_ENTRIES) {
                    break;
                }

                $userId = $entry->userId;
                if (null === $userId || '' === $userId) {
                    $anonymous = true;

                    continue;
                }

                // the id is kept in the value: a numeric key would turn into an int
                $users[(string) $userId] ??= ['id' => (string) $userId, 'name' => $entry->username ?? (string) $userId];
            }

            $list = array_values($users);

            usort($list, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

            return ['users' => $list, 'anonymous' => $anonymous];
        };

        /** @var Configuration $configuration */
        $configuration = $this->reader->getProvider()->getConfiguration();

        // a read scope depends on the reader (a tenant…): its authors are not shared through the cache
        return $this->cache instanceof ActivityGraphProvider && null === $configuration->getReadScope()
            ? $this->cache->remember('dh_auditor.authors.'.md5($class.'#'.$id.'#'.($attached ? '1' : '0')), $compute, \is_array(...))
            : $compute();
    }

    /**
     * The creation (or initial snapshot) of the object, when it was audited.
     */
    public function creation(string $class, string $id): ?Entry
    {
        $entries = $this->reader->createQuery($class, ['object_id' => $id, 'type' => 'insert', 'page_size' => 1])
            ->resetOrderBy()
            ->addOrderBy(Query::ID, 'ASC')
            ->execute()
        ;

        return $entries[0] ?? null;
    }

    /**
     * Audit tables read by the history: the table of the object, then those of the entities attached to it.
     *
     * @return list<string>
     */
    public function tables(string $class, bool $attached): array
    {
        /** @var Configuration $configuration */
        $configuration = $this->reader->getProvider()->getConfiguration();
        $tables = [$this->reader->getEntityAuditTableName($class)];
        if ($attached) {
            foreach (array_keys($configuration->getEntities()) as $entity) {
                if ($configuration->hasRoots((string) $entity) && $this->reader->getProvider()->hasAuditTable((string) $entity)) {
                    $tables[] = $this->reader->getEntityAuditTableName((string) $entity);
                }
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * Tells whether entities can be attached to a history (some entity declares roots).
     */
    public function hasAttached(): bool
    {
        /** @var Configuration $configuration */
        $configuration = $this->reader->getProvider()->getConfiguration();

        return $configuration->hasRoots();
    }
}
