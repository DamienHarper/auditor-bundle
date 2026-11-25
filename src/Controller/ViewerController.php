<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Controller;

use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\BlameOrigin;
use DH\Auditor\History\Change;
use DH\Auditor\History\ChangeExtractor;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Model\Entry;
use DH\Auditor\Model\TransactionType;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\JsonPlatformHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\SchemaHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\NullFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\RangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\SimpleFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\AuditorBundle\Enum\EntitySort;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\History\HistoryPhraser;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\Tests\Controller\ViewerControllerTest;
use DH\AuditorBundle\Viewer\ActionNavigator;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\HistoryFilters;
use DH\AuditorBundle\Viewer\HistoryRows;
use DH\AuditorBundle\Viewer\ObjectExistence;
use DH\AuditorBundle\Viewer\ObjectHistory;
use DH\AuditorBundle\Viewer\Overview;
use DH\AuditorBundle\Viewer\RevisionComparator;
use DH\AuditorBundle\Viewer\WholeActions;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException as SymfonyAccessDeniedException;
use Symfony\Component\Uid\Ulid;
use Twig\Environment;

/**
 * @see ViewerControllerTest
 */
#[AsController]
final readonly class ViewerController
{
    /**
     * Header sent by viewer.js to load more rows of a list as an HTML fragment.
     */
    public const string FRAGMENT_HEADER = 'X-DH-Auditor-Fragment';

    private const int RECENT_ACTIONS = 10;

    private const int LAST_CHANGES = 8;

    /**
     * Entries listed at most in the details of an action.
     */
    private const int MAX_ACTION_ENTRIES = 200;

    public function __construct(
        private Environment $environment,
        private ?ActivityGraphProvider $activityGraphProvider = null,
    ) {}

    /**
     * Home page: the most recent actions across the audited entities, and the entities with their number of actions.
     */
    #[Route(path: '/audit', name: 'dh_auditor_list_audits', methods: ['GET'])]
    public function listAuditsAction(Request $request, Reader $reader, Overview $overview, HistoryPhraser $phraser, ObjectExistence $existence): Response
    {
        $sort = EntitySort::fromQueryParam($request->query->get('sort'));

        $audited = [];
        foreach ($overview->entities() as $entity => $table) {
            $query = $reader->createQuery($entity, ['page_size' => 1]);

            // the latest entry is read through an index; an entity without any is not listed
            $latest = $query->execute()[0] ?? null;
            if ($latest instanceof Entry) {
                $audited[$entity] = [
                    'table' => $table,
                    'count' => $overview->actionCount($entity),
                    'latest' => $latest,
                ];
            }
        }

        $activityGraphEnabled = $this->activityGraphProvider instanceof ActivityGraphProvider;
        if ($activityGraphEnabled) {
            foreach ($audited as $entity => &$data) {
                $activityData = $this->activityGraphProvider->getActivityDataWithRaw($entity, $reader);
                $data['activityGraph'] = $activityData['normalized'];
                $data['activityGraphRaw'] = $activityData['raw'];
                $data['activityGraphDates'] = $activityData['dates'];
            }

            unset($data);
        }

        return $this->renderView('@DHAuditor/Audit/audits.html.twig', [
            'audited' => $this->sortAudited($audited, $sort),
            'activity' => $this->rows($overview->recentActivity(array_keys($audited), self::RECENT_ACTIONS), $phraser, $existence),
            'sort' => $sort->value,
            'sortOptions' => EntitySort::cases(),
            'activityGraphEnabled' => $activityGraphEnabled,
            'activityGraphDays' => $this->activityGraphProvider instanceof ActivityGraphProvider ? $this->activityGraphProvider->days : 30,
        ]);
    }

    /**
     * One action (a database transaction): what it did, then every entry it wrote. Opened from the history of an
     * object (?root=FQCN:id) that holds it, it links to the previous and next actions of that history (unfiltered,
     * attached entities included, within the latest ActionNavigator::MAX_ENTRIES entries).
     */
    #[Route(path: '/audit/transaction/{hash}', name: 'dh_auditor_show_transaction_stream', methods: ['GET'])]
    public function showTransactionAction(Request $request, Reader $reader, ObjectHistory $history, HistoryPhraser $phraser, ObjectExistence $existence, string $hash): Response
    {
        $entries = [];
        $tables = [];
        foreach ($reader->getAuditsByTransactionId($hash) as $entity => $entityEntries) {
            \assert(\is_string($entity) && \is_array($entityEntries));
            $tables[$entity] = $reader->getEntityAuditTableName($entity);
            foreach ($entityEntries as $entry) {
                \assert($entry instanceof Entry);
                $entries[] = ['entity' => $entity, 'entry' => $entry];
            }
        }

        if ([] === $entries) {
            throw new NotFoundHttpException('Action not found.');
        }

        // in the order they were written
        usort($entries, static fn (array $a, array $b): int => [$a['entry']->createdAt, $a['entity'], $a['entry']->id] <=> [$b['entry']->createdAt, $b['entity'], $b['entry']->id]);

        $item = new HistoryItem($hash, array_reverse(array_column($entries, 'entry')));

        // the history the action was opened from, kept when the reader may view it and the action is part of it
        $root = $this->parseRoot($request, $reader);
        $neighbours = ['newer' => null, 'older' => null, 'found' => false];
        if (null !== $root) {
            try {
                $neighbours = ActionNavigator::neighbours($history->query($root['class'], $root['id'], new HistoryFilters())->iterate(), $hash);
            } catch (AccessDeniedException) {
                $neighbours['found'] = false;
            }

            if (!$neighbours['found']) {
                $root = null;
            }
        }

        $rows = $this->rows([$item], $phraser, $existence, root: null === $root ? null : [$root['class'], $root['id']]);
        if (null !== $root) {
            $rows[0]['phrase'] = $phraser->focus($rows[0]['phrase'], $root['class'], $root['id']);
        }

        return $this->renderView('@DHAuditor/Audit/transaction_stream.html.twig', [
            'hash' => $hash,
            'row' => $rows[0],
            'entries' => \array_slice($entries, 0, self::MAX_ACTION_ENTRIES),
            'entryCount' => \count($entries),
            'tables' => $tables,
            'objects' => \count(array_unique(array_map(static fn (array $e): string => $e['entity'].'#'.$e['entry']->objectId, $entries))),
            'ulidDate' => $this->ulidDate($hash)?->setTimezone($this->timezone($reader)),
            'root' => $root,
            'neighbours' => $neighbours,
        ]);
    }

    /**
     * Everything a user did, across every audited entity the reader may view (the others are skipped), told as
     * actions, page_size actions per page. The user is identified by the blame_id column.
     */
    #[Route(path: '/audit/user/{id}', name: 'dh_auditor_show_user_stream', requirements: ['id' => '.{1,255}'], methods: ['GET'])]
    public function showUserStreamAction(Request $request, Reader $reader, HistoryPhraser $phraser, ObjectExistence $existence, string $id): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $filters = HistoryFilters::fromRequest($request);

        /** @var Configuration $configuration */
        $configuration = $reader->getProvider()->getConfiguration();
        $pageSize = $configuration->getViewerPageSize();
        $timezone = $this->timezone($reader);

        /** @var array<string, Query> $queries */
        $queries = [];
        $entities = [];
        $viewable = [];

        /** @var list<array{entity: string, entry: Entry}> $latestRows */
        $latestRows = [];
        foreach (array_keys($configuration->getEntities()) as $entity) {
            $entity = (string) $entity;
            if (!$reader->getProvider()->hasAuditTable($entity)) {
                continue;
            }

            try {
                $query = $reader->createQuery($entity, array_filter(['blame_id' => $id, 'page_size' => null, 'type' => $filters->what], static fn (mixed $value): bool => null !== $value));
            } catch (AccessDeniedException) {
                continue;
            }

            $viewable[] = $entity;
            if (null !== $filters->days) {
                $query->addFilter(new DateRangeFilter(Query::CREATED_AT, new \DateTimeImmutable('today', $timezone)->modify(\sprintf('-%d days', $filters->days - 1))));
            }

            $latest = (clone $query)->limit(1)->execute()[0] ?? null;
            if ($latest instanceof Entry) {
                $entities[] = $entity;
                if (null === $filters->entity || $filters->entity === $entity) {
                    $queries[$entity] = $query;
                    $latestRows[] = ['entity' => $entity, 'entry' => $latest];
                }
            }
        }

        if (null !== $filters->entity && !\in_array($filters->entity, $viewable, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown entity "%s".', $filters->entity));
        }

        $actionCount = Query::countTransactionsOf(array_values($queries));
        $page = min($page, max(1, (int) ceil($actionCount / $pageSize)));
        $pageEntries = array_column(WholeActions::page($queries, $pageSize, ($page - 1) * $pageSize), 'entry');

        // the most recent entry of the user tells their latest name and firewall
        usort($latestRows, WholeActions::compare(...));

        $parameters = [
            'id' => $id,
            'latest' => $latestRows[0]['entry'] ?? null,
            'rows' => $this->rows(HistoryGrouper::byTransaction($pageEntries), $phraser, $existence),
            'actionCount' => $actionCount,
            'entities' => $entities,
            'filters' => $filters,
            'periods' => HistoryFilters::PERIODS,
            'types' => TransactionType::cases(),
            // only the filters of this page
            'more' => ($page * $pageSize) < $actionCount ? ['page' => $page + 1] + array_intersect_key($filters->toQuery(), ['on' => true, 'what' => true, 'when' => true]) : null,
            'moreRoute' => 'dh_auditor_show_user_stream',
            'moreRouteParameters' => ['id' => $id],
        ];

        return $this->isFragment($request)
            ? $this->renderView('@DHAuditor/History/_actions.html.twig', $parameters + ['continued' => $request->query->getString('day'), 'show_author' => false])
            : $this->renderView('@DHAuditor/Audit/user_stream.html.twig', $parameters);
    }

    /**
     * With an id, the history of an object and of the entities attached to it, one page of actions at a time; without
     * id, the audit stream of a whole entity.
     */
    #[Route(path: '/audit/{entity}/{id}', name: 'dh_auditor_show_entity_stream', methods: ['GET'])]
    public function showEntityStreamAction(Request $request, Reader $reader, ObjectHistory $history, HistoryPhraser $phraser, ObjectExistence $existence, string $entity, int|string|null $id = null): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        if (null !== $id && '' !== (string) $id) {
            return $this->objectHistory($request, $reader, $history, $phraser, $existence, $entity, (string) $id);
        }

        $page = max(1, $request->query->getInt('page', 1));

        // Get filter parameters
        $typeFilter = $request->query->get('type');
        $userFilter = $request->query->get('user');
        $dateFromFilter = $request->query->getString('date_from');
        $dateToFilter = $request->query->getString('date_to');
        $fieldFilter = $request->query->get('field');

        try {
            /** @var Configuration $configuration */
            $configuration = $reader->getProvider()->getConfiguration();
            $pageSize = $configuration->getViewerPageSize();

            // Build query options with filters: the page is read by actions, not by entries
            $queryOptions = [
                'page_size' => null,
            ];

            if (null !== $typeFilter && '' !== $typeFilter) {
                $queryOptions['type'] = $typeFilter;
            }

            // Handle user filter - special case for anonymous
            $isAnonymousFilter = HistoryFilters::ANONYMOUS === $userFilter;
            if (null !== $userFilter && '' !== $userFilter && !$isAnonymousFilter) {
                $queryOptions['blame_id'] = $userFilter;
            }

            if (null !== $fieldFilter && '' !== $fieldFilter) {
                $queryOptions['changed_field'] = $fieldFilter;
            }

            try {
                $query = $reader->createQuery($entity, $queryOptions);
            } catch (InvalidArgumentException $e) {
                throw new BadRequestHttpException($e->getMessage(), $e);
            }

            $timezone = $this->timezone($reader);
            $dateFrom = $this->parseDateFilter($dateFromFilter, $timezone);
            $dateTo = $this->parseDateFilter($dateToFilter, $timezone)?->setTime(23, 59, 59);
            if ($dateFrom instanceof \DateTimeImmutable && $dateTo instanceof \DateTimeImmutable && $dateFrom > $dateTo) {
                throw new BadRequestHttpException('The start date must not be later than the end date.');
            }

            if ($dateFrom instanceof \DateTimeImmutable || $dateTo instanceof \DateTimeImmutable) {
                $query->addFilter(new DateRangeFilter(Query::CREATED_AT, $dateFrom, $dateTo));
            }

            // Add NullFilter for anonymous users
            if ($isAnonymousFilter) {
                $query->addFilter(new NullFilter(Query::USER_ID));
            }

            // Get available filter options (types, users and changed fields)
            $filterOptions = $this->getFilterOptions($reader, $entity, null);

            if (isset($queryOptions['changed_field'])) {
                if ([] === $filterOptions['fields']) {
                    throw new BadRequestHttpException('Filtering on a changed field is not available for this audit table.');
                }

                // Keep a field that is no longer mapped (renamed, removed) selectable.
                if (!\in_array($queryOptions['changed_field'], $filterOptions['fields'], true)) {
                    $filterOptions['fields'][] = $queryOptions['changed_field'];
                    sort($filterOptions['fields']);
                }
            }

            $pager = $this->paginateActions($query, $page, $pageSize);
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        // the actions of the page, whole
        $pageEntries = array_column(WholeActions::page([$entity => $query], $pageSize, ($pager['currentPage'] - 1) * $pageSize), 'entry');

        return $this->renderView('@DHAuditor/Audit/entity_stream.html.twig', [
            'entity' => $entity,
            'paginator' => $pager,
            'rows' => $this->rows(HistoryGrouper::byTransaction($pageEntries), $phraser, $existence),
            'filters' => [
                'type' => $typeFilter,
                'user' => $userFilter,
                'date_from' => $dateFromFilter,
                'date_to' => $dateToFilter,
                'field' => $fieldFilter,
            ],
            'filterOptions' => $filterOptions,
        ]);
    }

    /**
     * Lists the revisions of an audited object so that two of them can be compared: the first
     * one picked becomes the baseline (?selected=), picking a second one opens the comparison.
     */
    #[Route(path: '/audit/{entity}/{id}/revisions', name: 'dh_auditor_select_revisions', methods: ['GET'])]
    public function selectRevisionsAction(Request $request, Reader $reader, string $entity, int|string $id): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        $page = max(1, $request->query->getInt('page', 1));
        $selectedId = $this->parseRevisionId($request, 'selected');

        try {
            /** @var Configuration $configuration */
            $configuration = $reader->getProvider()->getConfiguration();
            $pageSize = $configuration->getViewerPageSize();

            $query = $reader->createQuery($entity, ['object_id' => $id, 'page' => $page, 'page_size' => $pageSize]);
            $pager = $reader->paginate($query, $page, $pageSize);

            $selected = null;
            if (null !== $selectedId) {
                $selected = $this->createRevisionQuery($reader, $entity, $id)
                    ->addFilter(new SimpleFilter(Query::ID, $selectedId))
                    ->execute()[0] ?? null
                ;

                // The baseline must be a revision of this very object.
                if (null === $selected) {
                    throw new NotFoundHttpException('Revision not found.');
                }
            }
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        return $this->renderView('@DHAuditor/Audit/revisions.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'paginator' => $pager,
            'selected' => $selected,
        ]);
    }

    /**
     * Shows what changed on an audited object between two of its revisions.
     *
     * Only the entries recorded between the two revisions are loaded and composed (see
     * RevisionComparator): the result stays exact when older entries have been purged.
     */
    #[Route(path: '/audit/{entity}/{id}/compare', name: 'dh_auditor_compare_revisions', methods: ['GET'])]
    public function compareRevisionsAction(Request $request, Reader $reader, string $entity, int|string $id): Response
    {
        $entity = UrlHelper::paramToNamespace($entity);

        if (!$reader->getProvider()->isAuditable($entity)) {
            throw new NotFoundHttpException('Not Found');
        }

        $fromId = $this->parseRevisionId($request, 'from');
        $toId = $this->parseRevisionId($request, 'to');

        if (null === $fromId || null === $toId) {
            throw new BadRequestHttpException('Both "from" and "to" revisions are required.');
        }

        // Always compare in chronological order, whichever revision was picked first.
        [$fromId, $toId] = [min($fromId, $toId), max($fromId, $toId)];

        try {
            // Audit entry IDs grow with time within an audit table: they give the chronological order.
            $range = array_values($this->createRevisionQuery($reader, $entity, $id)
                ->addFilter(new RangeFilter(Query::ID, $fromId, $toId))
                ->execute());
            $before = $this->createRevisionQuery($reader, $entity, $id, 'DESC')
                ->addFilter(new RangeFilter(Query::ID, null, $fromId - 1))
                ->limit(1)
                ->execute()[0] ?? null
            ;
            $after = $this->createRevisionQuery($reader, $entity, $id)
                ->addFilter(new RangeFilter(Query::ID, $toId + 1))
                ->limit(1)
                ->execute()[0] ?? null
            ;
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        }

        $from = $range[0] ?? null;
        $to = [] === $range ? null : $range[\count($range) - 1];

        // Both revisions must exist and belong to this very object.
        if (null === $from || null === $to || $from->id !== $fromId || $to->id !== $toId) {
            throw new NotFoundHttpException('Revision not found.');
        }

        $count = \count($range);

        // "Previous" and "next" shift both revisions by one, so users can step through history.
        $previous = null === $before ? null : [
            'from' => $before->id,
            'to' => $count > 1 ? $range[$count - 2]->id : $before->id,
        ];
        $next = null === $after ? null : [
            'from' => $count > 1 ? $range[1]->id : $after->id,
            'to' => $after->id,
        ];

        return $this->renderView('@DHAuditor/Audit/compare.html.twig', [
            'entity' => $entity,
            'id' => $id,
            'from' => $from,
            'to' => $to,
            'comparison' => new RevisionComparator()->compare(\array_slice($range, 1)),
            'previous' => $previous,
            'next' => $next,
        ]);
    }

    /**
     * History of an object and of the entities attached to it.
     */
    private function objectHistory(Request $request, Reader $reader, ObjectHistory $history, HistoryPhraser $phraser, ObjectExistence $existence, string $entity, string $id): Response
    {
        $filters = HistoryFilters::fromRequest($request);
        $after = '' === $request->query->getString('after') ? null : $request->query->getString('after');
        $timezone = $this->timezone($reader);

        /** @var Configuration $configuration */
        $configuration = $reader->getProvider()->getConfiguration();

        try {
            $page = $history->page($entity, $id, $filters, $after, $configuration->getViewerPageSize(), $timezone);
        } catch (AccessDeniedException) {
            throw new SymfonyAccessDeniedException('Access Denied.');
        } catch (InvalidArgumentException $e) {
            // a cursor or a field of the query string; anything else is an error of the application or its configuration
            if (null === $after && null === $filters->field) {
                throw $e;
            }

            throw new BadRequestHttpException($e->getMessage(), $e);
        }

        $parameters = [
            'entity' => $entity,
            'id' => $id,
            'filters' => $filters,
            'rows' => $this->rows($page->group(), $phraser, $existence, root: [$entity, $id]),
            'more' => $page->hasMore && null !== $page->lastCursor ? ['after' => $page->lastCursor] + $filters->toQuery() : null,
            'moreRoute' => 'dh_auditor_show_entity_stream',
            'moreRouteParameters' => ['entity' => UrlHelper::namespaceToParam($entity), 'id' => $id],
            'root' => $entity.':'.$id,
        ];

        if ($this->isFragment($request)) {
            return $this->renderView('@DHAuditor/History/_actions.html.twig', $parameters + ['continued' => $request->query->getString('day')]);
        }

        $fields = $this->getAuditedFields($reader, $entity);
        $connection = $reader->getProvider()->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $creation = $history->creation($entity, $id);

        $lastChanges = array_filter($reader->getLastChanges($entity, $id, $fields), static fn (?Change $change): bool => $change instanceof Change);
        uasort($lastChanges, static fn (Change $a, Change $b): int => [$b->entry->createdAt, $b->entry->id] <=> [$a->entry->createdAt, $a->entry->id]);

        $latestOwn = array_find($page->entries, static fn (Entry $entry): bool => ChangeExtractor::entityOf($entry) === $entity && $entry->objectId === $id);
        $label = ($latestOwn ?? $creation)?->getDiffSource()['label'] ?? null;

        return $this->renderView('@DHAuditor/Audit/object_history.html.twig', $parameters + [
            'label' => \is_string($label) ? $label : null,
            'creation' => $creation,
            'creationItem' => $creation instanceof Entry ? new HistoryItem((string) $creation->transactionId, [$creation]) : null,
            'actionCount' => $history->countActions($entity, $id, $filters->attached),
            'entryCount' => $history->count($entity, $id, $filters->attached),
            'authors' => $history->authors($entity, $id, $filters->attached),
            'fields' => JsonPlatformHelper::isJsonSearchSupported($connection) ? $fields : [],
            'types' => TransactionType::cases(),
            'origins' => BlameOrigin::cases(),
            'periods' => HistoryFilters::PERIODS,
            'hasAttached' => $history->hasAttached(),
            'lastChanges' => \array_slice($lastChanges, 0, self::LAST_CHANGES, true),
            'tables' => $history->tables($entity, $filters->attached),
        ]);
    }

    /**
     * Phrases of the actions of a page, the objects they name checked for deletion in one query per class.
     *
     * @param list<HistoryItem>                $items
     * @param null|array{0: string, 1: string} $root  class and id of the history the actions are shown in
     *
     * @return list<array{item: HistoryItem, phrase: Phrase, context: ?Phrase}>
     */
    private function rows(array $items, HistoryPhraser $phraser, ObjectExistence $existence, ?array $root = null): array
    {
        return new HistoryRows($phraser, $existence)->build($items, $root);
    }

    /**
     * The history an action was opened from: ?root=FQCN:id.
     *
     * @return null|array{class: string, id: string, param: string}
     */
    private function parseRoot(Request $request, Reader $reader): ?array
    {
        $root = $request->query->getString('root');
        // a class name holds no colon, an identifier may
        $separator = mb_strpos($root, ':');
        if (false === $separator) {
            return null;
        }

        $class = mb_substr($root, 0, $separator);
        $id = mb_substr($root, $separator + 1);

        return '' !== $id && $reader->getProvider()->isAuditable($class) ? ['class' => $class, 'id' => $id, 'param' => $root] : null;
    }

    /**
     * Date of a ULID transaction id, or null for a legacy (SHA1) one.
     */
    private function ulidDate(string $transactionId): ?\DateTimeImmutable
    {
        return Ulid::isValid($transactionId, Ulid::FORMAT_BASE_32) ? Ulid::fromString($transactionId)->getDateTime() : null;
    }

    /**
     * Pager of a list of actions, in the shape of Reader::paginate() (without results): numResults counts the actions,
     * and a page beyond the last one is the last one.
     *
     * @return array{currentPage: int, hasPreviousPage: bool, hasNextPage: bool, previousPage: ?int, nextPage: ?int, numPages: int, haveToPaginate: bool, numResults: int, pageSize: int}
     */
    private function paginateActions(Query $query, int $page, int $pageSize): array
    {
        $numResults = $query->countTransactions();
        $numPages = (int) ceil($numResults / $pageSize);
        $currentPage = min(max(1, $page), max(1, $numPages));
        $hasPreviousPage = $currentPage > 1;
        $hasNextPage = $currentPage < $numPages;

        return [
            'currentPage' => $currentPage,
            'hasPreviousPage' => $hasPreviousPage,
            'hasNextPage' => $hasNextPage,
            'previousPage' => $hasPreviousPage ? $currentPage - 1 : null,
            'nextPage' => $hasNextPage ? $currentPage + 1 : null,
            'numPages' => $numPages,
            'haveToPaginate' => $numResults > $pageSize,
            'numResults' => $numResults,
            'pageSize' => $pageSize,
        ];
    }

    /**
     * Tells whether the request asks only for more rows of a list ("show older actions").
     */
    private function isFragment(Request $request): bool
    {
        return $request->headers->has(self::FRAGMENT_HEADER);
    }

    private function timezone(Reader $reader): \DateTimeZone
    {
        return new \DateTimeZone($reader->getProvider()->getAuditor()->getConfiguration()->timezone);
    }

    private function parseDateFilter(string $value, \DateTimeZone $timezone): ?\DateTimeImmutable
    {
        if ('' === $value) {
            return null;
        }

        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new BadRequestHttpException('Dates must use the YYYY-MM-DD format.');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('Dates must be valid calendar dates in YYYY-MM-DD format.');
        }

        return $date;
    }

    /**
     * Returns the audit entry ID passed in the query string, or null when it is absent.
     */
    private function parseRevisionId(Request $request, string $name): ?int
    {
        $value = $request->query->getString($name);

        if ('' === $value) {
            return null;
        }

        if (1 !== preg_match('/^[1-9]\d{0,17}$/D', $value)) {
            throw new BadRequestHttpException(\sprintf('The "%s" revision must be a positive integer.', $name));
        }

        return (int) $value;
    }

    /**
     * Query on the revisions of a single object, ordered by audit entry ID.
     */
    private function createRevisionQuery(Reader $reader, string $entity, int|string $id, string $direction = 'ASC'): Query
    {
        return $reader->createQuery($entity, ['object_id' => $id, 'page_size' => null])
            ->resetOrderBy()
            ->addOrderBy(Query::ID, $direction)
        ;
    }

    /**
     * Sorts the audited entities; ties on event count or latest activity are broken by name (A-Z).
     *
     * @template T of array{count: int, latest: ?Entry}
     *
     * @param array<array-key, T> $audited audit summaries indexed by entity FQCN
     *
     * @return array<array-key, T>
     */
    private function sortAudited(array $audited, EntitySort $sort): array
    {
        uksort($audited, function (int|string $a, int|string $b) use ($audited, $sort): int {
            $byName = strcasecmp($this->shortEntityName((string) $a), $this->shortEntityName((string) $b)) ?: strcmp((string) $a, (string) $b);

            return match ($sort) {
                EntitySort::NameAsc => $byName,
                EntitySort::NameDesc => -$byName,
                EntitySort::CountAsc => ($audited[$a]['count'] <=> $audited[$b]['count']) ?: $byName,
                EntitySort::CountDesc => ($audited[$b]['count'] <=> $audited[$a]['count']) ?: $byName,
                EntitySort::ActivityAsc => ($this->latestActivityTimestamp($audited[$a]['latest']) <=> $this->latestActivityTimestamp($audited[$b]['latest'])) ?: $byName,
                EntitySort::ActivityDesc => ($this->latestActivityTimestamp($audited[$b]['latest']) <=> $this->latestActivityTimestamp($audited[$a]['latest'])) ?: $byName,
            };
        });

        return $audited;
    }

    private function shortEntityName(string $entity): string
    {
        $position = mb_strrpos($entity, '\\');

        return false === $position ? $entity : mb_substr($entity, $position + 1);
    }

    private function latestActivityTimestamp(?Entry $latest): int
    {
        return $latest?->createdAt?->getTimestamp() ?? 0;
    }

    /**
     * Get available filter options (distinct types, users and changed fields) for an entity.
     *
     * Supports both schema v1 (legacy: blame_user column) and schema v2 (blame JSON column).
     *
     * @return array{types: array<string>, users: array<array{id: string, name: string}>, hasAnonymous: bool, fields: array<string>}
     */
    private function getFilterOptions(Reader $reader, string $entity, int|string|null $id): array
    {
        $storageService = $reader->getProvider()->getStorageServiceForEntity($entity);
        $connection = $storageService->getEntityManager()->getConnection();
        $auditTable = $reader->getEntityAuditTableName($entity);

        // Introspect the columns of the audit table (not the whole database) to detect the schema version.
        // v2 tables have a 'blame' JSON column; v1 (legacy) tables use separate 'blame_user' columns.
        $columns = SchemaHelper::getTableColumnNames($connection, $auditTable);
        $hasBlameColumn = \in_array('blame', $columns, true);      // schema v2
        $hasLegacyBlame = \in_array('blame_user', $columns, true); // schema v1 (legacy)

        // Build base condition for object_id if provided
        $params = [];
        $objectIdCondition = '';
        if (null !== $id) {
            $objectIdCondition = 'object_id = :object_id';
            $params['object_id'] = $id;
        }

        // Get distinct types
        if ('' === $objectIdCondition && $connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // PostgreSQL reads the whole type index for a DISTINCT: each known type is looked up in it instead
            $typesSql = implode(' UNION ALL ', array_map(
                static fn (TransactionType $type): string => \sprintf(
                    "SELECT '%1\$s' AS type FROM (SELECT 1 FROM %2\$s WHERE type = '%1\$s' LIMIT 1) t_%1\$s",
                    $type->value,
                    $auditTable,
                ),
                TransactionType::cases(),
            ));
        } else {
            $typesSql = \sprintf(
                'SELECT DISTINCT type FROM %s %s',
                $auditTable,
                '' !== $objectIdCondition ? 'WHERE '.$objectIdCondition : ''
            );
        }

        /** @var list<string> $types */
        $types = $connection->executeQuery($typesSql, $params)->fetchFirstColumn();
        sort($types);

        $users = [];

        if ($hasBlameColumn) {
            // Schema v2: username is encoded as JSON in the 'blame' column, which no index can cover.
            // Only the latest row of each user is read: MAX(id) per blame_id is resolved from the
            // blame_id index alone, then the blame of those rows is fetched by primary key (a derived
            // table rather than IN (subquery), which MySQL would run as a full scan).
            // A blame also holds the IP and firewall: one user is listed once, under their latest username.
            $usersSql = \sprintf(
                'SELECT a.blame_id, a.blame FROM %1$s a INNER JOIN (SELECT MAX(id) AS id FROM %1$s WHERE blame_id IS NOT NULL %2$s GROUP BY blame_id) latest ON a.id = latest.id',
                $auditTable,
                '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
            );

            $usersResult = $connection->executeQuery($usersSql, $params)->fetchAllAssociative();
            $users = array_map(static function (array $row): array {
                $blameId = $row['blame_id'];
                $blameUser = null;

                if (\is_string($row['blame'] ?? null)) {
                    $decoded = json_decode($row['blame'], true);
                    $blameUser = \is_array($decoded) ? ($decoded['username'] ?? null) : null;
                }

                return [
                    'id' => \is_scalar($blameId) ? (string) $blameId : '',
                    'name' => \is_string($blameUser) ? $blameUser : (\is_scalar($blameId) ? (string) $blameId : ''),
                ];
            }, $usersResult);
            usort($users, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);
        } elseif ($hasLegacyBlame) {
            // Schema v1 (legacy): username is stored in the dedicated 'blame_user' column.
            $usersSql = \sprintf(
                'SELECT DISTINCT blame_id, blame_user FROM %s WHERE blame_id IS NOT NULL %s ORDER BY blame_id',
                $auditTable,
                '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
            );

            $usersResult = $connection->executeQuery($usersSql, $params)->fetchAllAssociative();
            $users = array_map(static function (array $row): array {
                $blameId = $row['blame_id'];
                $blameUser = isset($row['blame_user']) && '' !== $row['blame_user'] ? $row['blame_user'] : null;

                return [
                    'id' => \is_scalar($blameId) ? (string) $blameId : '',
                    'name' => \is_string($blameUser) ? $blameUser : (\is_scalar($blameId) ? (string) $blameId : ''),
                ];
            }, $usersResult);
        }

        // If neither column exists, $users remains [] (no blame info available).

        // Check if there are anonymous entries (blame_id IS NULL): the first one is enough
        $anonymousSql = $connection->getDatabasePlatform()->modifyLimitQuery(\sprintf(
            'SELECT 1 FROM %s WHERE blame_id IS NULL %s',
            $auditTable,
            '' !== $objectIdCondition ? 'AND '.$objectIdCondition : ''
        ), 1);
        $hasAnonymous = false !== $connection->executeQuery($anonymousSql, $params)->fetchOne();

        // The changed field filter needs native JSON support and the schema v2 structure
        // (schema_version column); legacy tables must be migrated first.
        $fields = \in_array('schema_version', $columns, true) && JsonPlatformHelper::isJsonSearchSupported($connection)
            ? $this->getAuditedFields($reader, $entity)
            : [];

        return [
            'types' => $types,
            'users' => $users,
            'hasAnonymous' => $hasAnonymous,
            'fields' => $fields,
        ];
    }

    /**
     * Lists the fields of an entity that may appear in its audit entries, read from the Doctrine
     * mapping rather than from the audit table: fields and associations, minus the ignored
     * columns and the generated identifiers.
     *
     * @return list<string>
     */
    private function getAuditedFields(Reader $reader, string $entity): array
    {
        if (!class_exists($entity)) {
            return [];
        }

        /** @var Configuration $configuration */
        $configuration = $reader->getProvider()->getConfiguration();
        $ignoredColumns = $configuration->getIgnoredColumns();
        $entityConfiguration = $configuration->getEntities()[$entity] ?? null;
        if (\is_array($entityConfiguration) && \is_array($entityConfiguration['ignored_columns'] ?? null)) {
            $ignoredColumns = [...$ignoredColumns, ...array_filter($entityConfiguration['ignored_columns'], \is_string(...))];
        }

        $metadata = $reader->getProvider()->getAuditingServiceForEntity($entity)->getEntityManager()->getClassMetadata($entity);
        $generatedIdentifiers = $metadata->isIdentifierNatural() ? [] : $metadata->getIdentifierFieldNames();

        $fields = array_values(array_diff(
            [...array_keys($metadata->fieldMappings), ...array_keys($metadata->associationMappings)],
            $ignoredColumns,
            $generatedIdentifiers,
        ));
        sort($fields);

        return $fields;
    }

    private function renderView(string $view, array $parameters = []): Response
    {
        return new Response($this->environment->render($view, $parameters));
    }
}
