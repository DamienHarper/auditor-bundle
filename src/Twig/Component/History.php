<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig\Component;

use DH\Auditor\Exception\AccessDeniedException;
use DH\Auditor\Exception\InvalidArgumentException;
use DH\Auditor\History\HistoryGrouper;
use DH\Auditor\History\HistoryItem;
use DH\Auditor\Provider\Doctrine\Auditing\Root\ObjectIdentifier;
use DH\Auditor\Provider\Doctrine\Configuration;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Helper\DoctrineHelper;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\RootQuery;
use DH\AuditorBundle\History\HistoryElementInterface;
use DH\AuditorBundle\History\Phrase;
use DH\AuditorBundle\Tests\Twig\Component\HistoryTest;
use DH\AuditorBundle\Viewer\AuditViewerUrlGenerator;
use DH\AuditorBundle\Viewer\HistoryRows;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * <twig:DhAuditor:History :subject="post" :with="comments" />: the history of an audited object (and of the
 * entities attached to it) in a page of the application, its own elements mixed in by sort key.
 *
 * One page of actions at a time: the "older" link reloads the current URL with the cursor of the page in the query
 * string (the "cursorParameter" option, "dh_auditor_after" by default). An element of the application lands on the
 * page whose actions surround it, so that it is shown once whatever the page it is read from.
 *
 * Registered only when symfony/ux-twig-component is installed.
 *
 * @see HistoryTest
 */
final class History
{
    public const string NAME = 'DhAuditor:History';

    /**
     * The audited object.
     */
    public object $subject;

    /**
     * Elements of the application to mix with the actions (HistoryElementInterface).
     *
     * @var iterable<mixed>
     */
    public iterable $with = [];

    /**
     * Grouping of the entries into actions (one per transaction by default).
     */
    public ?HistoryGrouper $grouper = null;

    /**
     * Entries per page (the page size of the viewer by default), more when needed to keep the last action whole.
     */
    public ?int $limit = null;

    /**
     * Whether the actions on the entities attached to the subject are included.
     */
    public bool $attached = true;

    /**
     * Technical details of the actions: false, true, or the role that sees them.
     */
    public bool|string $details = false;

    /**
     * Cursor of the page to read (read from the query string by default). Unlike the one of the query string, an
     * invalid cursor given here is an error.
     */
    public ?string $after = null;

    /**
     * Query string parameter of the cursor.
     */
    public string $cursorParameter = 'dh_auditor_after';

    /**
     * Position of the most recent entry of the page (null while the history is empty): on the first page, read the
     * entries recorded since with RootQuery::fetch(since: firstCursor).
     */
    public ?string $firstCursor = null;

    /**
     * Rows of the page, most recent first: actions (row) and elements of the application (element).
     *
     * @var list<array{row: null|array{item: HistoryItem, phrase: Phrase, context: ?Phrase}, element: ?HistoryElementInterface}>
     */
    private array $rows = [];

    private ?string $olderUrl = null;

    private ?string $root = null;

    public function __construct(
        private readonly Reader $reader,
        private readonly DoctrineProvider $provider,
        private readonly ObjectIdentifier $identifier,
        private readonly HistoryRows $historyRows,
        private readonly AuditViewerUrlGenerator $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    #[PostMount]
    public function load(): void
    {
        $elements = [];
        foreach ($this->with as $element) {
            if (!$element instanceof HistoryElementInterface) {
                throw new \InvalidArgumentException(\sprintf('The elements of the "%s" component implement %s, %s given.', self::NAME, HistoryElementInterface::class, get_debug_type($element)));
            }

            $elements[] = $element;
        }

        usort($elements, static fn (HistoryElementInterface $a, HistoryElementInterface $b): int => $b->getSortKey() <=> $a->getSortKey());

        // the page the reader sees, also when the component is rendered by a sub-request (render(controller()))
        $request = $this->requestStack->getMainRequest();
        $after = $this->after ?? $this->cursorOf($request);

        $query = $this->query();
        if (!$query instanceof RootQuery) {
            // no history to read: the elements alone
            $this->rows = array_map(static fn (HistoryElementInterface $element): array => ['row' => null, 'element' => $element], $elements);

            return;
        }

        $limit = $this->limit ?? $this->pageSize();
        // an invalid "after" option, or a page size below 1, is an error of the application and is thrown
        $page = $query->fetch($limit, $after, groupKey: $this->grouper);

        $this->firstCursor = $page->firstCursor;

        // the elements of this page: newer than the first entry of the next page, and no newer than the first entry
        // of this one unless it is the first page
        $upper = null === $after || $page->isEmpty() ? null : $page->entries[0]->getSortKey();
        $lower = null;
        if ($page->hasMore) {
            $next = $query->fetch(1, $page->lastCursor, groupKey: false);
            $lower = $next->isEmpty() ? null : $next->entries[0]->getSortKey();
        }

        if (null !== $after && $page->isEmpty()) {
            // past the end of the history (its last entries pruned since the link was made): the elements were shown
            // on the previous pages
            $elements = [];
        }

        $elements = array_values(array_filter($elements, static fn (HistoryElementInterface $element): bool => (null === $upper || $element->getSortKey() <= $upper) && (null === $lower || $element->getSortKey() > $lower)));

        $rows = [];
        foreach ($this->historyRows->build($page->group($this->grouper)) as $row) {
            $key = $row['item']->entries[0]->getSortKey();
            while ([] !== $elements && $elements[0]->getSortKey() > $key) {
                $rows[] = ['row' => null, 'element' => array_shift($elements)];
            }

            $rows[] = ['row' => $row, 'element' => null];
        }

        foreach ($elements as $element) {
            $rows[] = ['row' => null, 'element' => $element];
        }

        $this->rows = $rows;

        if ($page->hasMore && null !== $page->lastCursor && $request instanceof Request) {
            $parameters = $request->query->all();
            $parameters[$this->cursorParameter] = $page->lastCursor;
            $this->olderUrl = $request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($parameters);
        }
    }

    /**
     * @return list<array{row: null|array{item: HistoryItem, phrase: Phrase, context: ?Phrase}, element: ?HistoryElementInterface}>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    /**
     * URL of the next page (older actions), or null on the last one.
     */
    public function getOlderUrl(): ?string
    {
        return $this->olderUrl;
    }

    /**
     * The subject as the links of the viewer name it ("FQCN:id"), or null when it has no history the reader may see.
     */
    public function getRoot(): ?string
    {
        return $this->root;
    }

    /**
     * History of the subject in the viewer, when it is enabled and the reader may see the history.
     */
    public function getViewerUrl(): ?string
    {
        return null === $this->root ? null : $this->urlGenerator->generateEntityUrl($this->subject);
    }

    public function showDetails(): bool
    {
        if (\is_bool($this->details)) {
            return $this->details;
        }

        return $this->authorizationChecker?->isGranted($this->details) ?? false;
    }

    /**
     * The history of the subject, or null when it has none the reader may see.
     */
    private function query(): ?RootQuery
    {
        $class = DoctrineHelper::getRealClassName($this->subject);
        if (!$this->provider->hasAuditTable($class)) {
            return null;
        }

        // a misconfigured entity manager is an error of the application, not a missing history
        $entityManager = $this->provider->getAuditingServiceForEntity($class)->getEntityManager();

        try {
            $id = $this->identifier->identify($entityManager, $this->subject);
        } catch (InvalidArgumentException) {
            // an identifier that cannot be turned into a string
            return null;
        }

        if (null === $id) {
            return null;
        }

        try {
            $query = $this->reader->createRootQuery($class, $id, $this->attached ? [] : ['include' => [$class]]);
        } catch (AccessDeniedException) {
            return null;
        }

        $this->root = $class.':'.$id;

        return $query;
    }

    /**
     * Cursor of the query string, when it holds one: a cursor edited by hand (or "?dh_auditor_after[]=") shows the
     * first page.
     */
    private function cursorOf(?Request $request): ?string
    {
        $cursor = $request?->query->all()[$this->cursorParameter] ?? null;

        return \is_string($cursor) && RootQuery::isCursor($cursor) ? $cursor : null;
    }

    private function pageSize(): int
    {
        /** @var Configuration $configuration */
        $configuration = $this->reader->getProvider()->getConfiguration();

        return $configuration->getViewerPageSize();
    }
}
