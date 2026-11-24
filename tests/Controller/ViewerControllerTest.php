<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Filter\DateRangeFilter;
use DH\Auditor\Provider\Doctrine\Persistence\Reader\Query;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\Enum\EntitySort;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
use DH\AuditorBundle\Viewer\WholeActions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;
use Twig\Extension\CoreExtension;

/**
 * @internal
 */
#[Small]
final class ViewerControllerTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private const string POST_URL = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post';

    private AbstractBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        // boots the Kernel and populates container
        $this->client = self::createClient();
        $this->client->disableReboot();

        // provider with 1 em for both storage and auditing
        $this->createAndInitDoctrineProvider();

        // declare audited entites
        $this->configureEntities();

        // setup entity and audit schemas
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();

        // setup (seed) entities
        $this->setupEntities();

        $entities = $this->provider->getConfiguration()->getEntities();
        $entities[Author::class]['roles']['view'] = ['ROLE_1', 'ROLE_2'];
        $this->provider->getConfiguration()->setEntities($entities);
    }

    /**
     * @see https://symfony.com/doc/current/testing.html
     * @see https://github.com/symfony/panther
     */
    public function testListAuditsAnonymously(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check page title
        $title = $crawler->filter('h1');
        $this->assertCount(1, $title, 'Page has a title');

        // Check cards
        $cards = $crawler->filter('a[href*="/audit/"]');
        $this->assertGreaterThanOrEqual(3, $cards->count(), 'There are at least 3 entity audit cards (Author excluded).');
    }

    #[Depends('testListAuditsAnonymously')]
    public function testListAuditsWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check cards - Author should not be visible
        $cards = $crawler->filter('a[href*="/audit/"]');
        $this->assertGreaterThanOrEqual(3, $cards->count(), 'There are at least 3 entity audit cards (Author excluded).');
    }

    #[Depends('testListAuditsWithRoleNotGrantedForAuthorAuditViewing')]
    public function testListAuditsWithRoleGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check cards
        $cards = $crawler->filter('a[href*="/audit/"]');
        $this->assertGreaterThanOrEqual(4, $cards->count(), 'There are at least 4 entity audit cards.');
    }

    #[DataProvider('provideListAuditsSortsEntitiesCases')]
    public function testListAuditsSortsEntities(EntitySort $sort): void
    {
        $reader = $this->createReader();

        // Fixtures are audited within the same second: age one entity's audits so that the activity
        // sorts are decided by the latest activity rather than by the name tie-breaker.
        $this->provider->getStorageServiceForEntity(Comment::class)->getEntityManager()->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET created_at = ?', $reader->getEntityAuditTableName(Comment::class)),
            ['2020-01-01 00:00:00']
        );

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit?sort='.$sort->value);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(\sprintf('#sort-entities[aria-label] option[value="%s"][selected]', $sort->value));
        $this->assertCount(\count(EntitySort::cases()), $crawler->filter('#sort-entities option'));

        $entities = $this->renderedEntities($crawler);
        $this->assertGreaterThanOrEqual(4, \count($entities), 'There are at least 4 entity audit cards.');
        $this->assertContains(Comment::class, $entities);

        $keys = [];
        foreach ($entities as $entity) {
            $query = $reader->createQuery($entity, ['page_size' => 1]);
            $latest = $query->execute()[0] ?? null;
            $keys[$entity] = [
                'name' => mb_strtolower(mb_substr((string) mb_strrchr('\\'.$entity, '\\'), 1)),
                // number of actions: distinct transactions
                'count' => \count(array_unique(array_map(static fn (Entry $entry): ?string => $entry->transactionId, $reader->createQuery($entity, ['page_size' => null])->execute()))),
                'activity' => $latest instanceof Entry ? (int) $latest->createdAt?->getTimestamp() : 0,
            ];
        }

        for ($i = 1, $n = \count($entities); $i < $n; ++$i) {
            $previous = $keys[$entities[$i - 1]];
            $current = $keys[$entities[$i]];
            $byName = $previous['name'] <=> $current['name'];

            $comparison = match ($sort) {
                EntitySort::NameAsc => $byName,
                EntitySort::NameDesc => -$byName,
                EntitySort::CountAsc => ($previous['count'] <=> $current['count']) ?: $byName,
                EntitySort::CountDesc => ($current['count'] <=> $previous['count']) ?: $byName,
                EntitySort::ActivityAsc => ($previous['activity'] <=> $current['activity']) ?: $byName,
                EntitySort::ActivityDesc => ($current['activity'] <=> $previous['activity']) ?: $byName,
            };

            $this->assertLessThanOrEqual(0, $comparison, \sprintf('"%s" is listed before "%s" with sort "%s".', $entities[$i - 1], $entities[$i], $sort->value));
        }
    }

    /**
     * @return iterable<string, array{EntitySort}>
     */
    public static function provideListAuditsSortsEntitiesCases(): iterable
    {
        foreach (EntitySort::cases() as $sort) {
            yield $sort->value => [$sort];
        }
    }

    public function testListAuditsFallsBackToNameAscendingOnInvalidSort(): void
    {
        $this->login(['ROLE_1']);
        $expected = $this->renderedEntities($this->client->request('GET', '/audit?sort=name_asc'));
        $reversed = $this->renderedEntities($this->client->request('GET', '/audit?sort=name_desc'));

        $this->assertSame(array_reverse($expected), $reversed, 'Descending name order is the reverse of ascending name order.');

        foreach (['', '?sort=', '?sort=NAME_ASC', '?sort=name;DROP%20TABLE%20author'] as $queryString) {
            $crawler = $this->client->request('GET', '/audit'.$queryString);

            $this->assertResponseIsSuccessful();
            $this->assertSame($expected, $this->renderedEntities($crawler), \sprintf('"%s" falls back to name ascending.', $queryString));
            $this->assertSelectorExists('#sort-entities option[value="name_asc"][selected]');
        }
    }

    #[Depends('testListAuditsAnonymously')]
    public function testShowEntityHistoryAnonymously(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check back link
        $backLink = $crawler->filter('a[href="/audit"]');
        $this->assertGreaterThanOrEqual(1, $backLink->count(), 'Back link to entities exists');

        // Check page title contains entity name
        $title = $crawler->filter('h1');
        $this->assertStringContainsString('Post', $title->text(), 'Page title contains Post');

        // Check entries are displayed
        $entries = $crawler->filter('details');
        $this->assertGreaterThanOrEqual(1, $entries->count(), 'There are audit entries');
    }

    public function testShowEntityHistoryFlagsSnapshots(): void
    {
        new Snapshotter($this->provider)->snapshot(Tag::class, force: true);

        $this->login();
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Tag');

        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');
        $this->assertStringContainsString('recorded the initial state of 5 Tag', $crawler->text(), 'Snapshot entries are told as such.');
        $this->assertStringContainsString('created 5 Tag', $crawler->text(), 'Regular insert entries are told as creations.');
    }

    #[Depends('testShowEntityHistoryAnonymously')]
    public function testShowEntityHistoryWithoutLoginForAuthorAuditViewing(): void
    {
        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'Response status is 403');
    }

    #[Depends('testShowEntityHistoryAnonymously')]
    public function testShowEntityHistoryWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_USER']);
        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        $this->assertSame(403, $this->client->getResponse()->getStatusCode(), 'Response status is 403');
    }

    #[Depends('testShowEntityHistoryWithRoleNotGrantedForAuthorAuditViewing')]
    public function testShowEntityHistoryWithRoleGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_1']);
        $uri = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author';
        $crawler = $this->client->request('GET', $uri);

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check page title contains entity name
        $title = $crawler->filter('h1');
        $this->assertStringContainsString('Author', $title->text(), 'Page title contains Author');

        // Check filtered entries not found
        $crawler = $this->client->request('GET', $uri.'?type=associate&user=1111111');
        $subtitle = $crawler->filter('h1+p');
        $this->assertStringContainsString('no action', $subtitle->text(), 'Subtitle counts no action');
    }

    #[Depends('testShowEntityHistoryWithRoleGrantedForAuthorAuditViewing')]
    public function testShowEntityHistoryOfUnauditedEntity(): void
    {
        $this->login();

        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Damn');

        $this->assertSame(404, $this->client->getResponse()->getStatusCode(), 'Response status is 404');
    }

    #[Depends('testShowEntityHistoryAnonymously')]
    public function testShowEntityHistoryFilteredByChangedField(): void
    {
        $this->login();
        $uri = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post';

        // The dropdown lists the audited fields and associations of Post, from its mapping.
        $crawler = $this->client->request('GET', $uri);
        $fieldOptions = $crawler->filter('#filter-field option')->each(static fn ($node) => $node->attr('value'));
        $this->assertSame(['', 'author', 'author_id', 'body', 'coauthor', 'comments', 'created_at', 'deleted_at', 'tags', 'title'], $fieldOptions, 'Audited fields of Post are offered');

        // Only the entries whose diff touches the field are listed.
        $crawler = $this->client->request('GET', $uri.'?field=coauthor');
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');
        $this->assertStringContainsString('2 actions', $crawler->filter('h1+p')->text(), 'Exactly 3 coauthor changes, in 2 actions');
        $this->assertSame('coauthor', $crawler->filter('#filter-field option[selected]')->attr('value'), 'The filtered field is selected');

        // Collections match the associations and dissociations recorded on them.
        $crawler = $this->client->request('GET', $uri.'?field=tags');
        $this->assertStringContainsString('2 actions', $crawler->filter('h1+p')->text(), '8 tag associations and 2 dissociations, in 2 actions');

        // Filters are combined.
        $crawler = $this->client->request('GET', $uri.'?field=tags&type=dissociate');
        $this->assertStringContainsString('1 action', $crawler->filter('h1+p')->text(), '2 tag dissociations, in 1 action');

        // Clearing the period keeps the changed field filter.
        $crawler = $this->client->request('GET', $uri.'?field=tags&date_from=2000-01-01');
        $this->assertStringContainsString('2 actions', $crawler->filter('h1+p')->text(), 'Combined with a period');
        $this->assertStringContainsString('field=tags', (string) $crawler->selectLink('Clear period')->attr('href'), 'Clearing the period keeps the field');

        // Keys of the association diffs are not fields.
        $crawler = $this->client->request('GET', $uri.'?field=source');
        $this->assertStringContainsString('no action', $crawler->filter('h1+p')->text(), 'source is not a field');

        // A field that is no longer mapped stays filterable and selectable.
        $crawler = $this->client->request('GET', $uri.'?field=subtitle');
        $this->assertStringContainsString('no action', $crawler->filter('h1+p')->text(), 'Unknown field matches nothing');
        $this->assertSame('subtitle', $crawler->filter('#filter-field option[selected]')->attr('value'), 'The unknown field is selected');
    }

    #[Depends('testShowEntityHistoryFilteredByChangedField')]
    public function testShowEntityHistoryFilteredByInvalidChangedField(): void
    {
        $this->login();
        $this->client->catchExceptions(false);
        $this->expectException(BadRequestHttpException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post?field='.urlencode("title' OR 1=1 --"));
    }

    #[Depends('testShowEntityHistoryFilteredByChangedField')]
    public function testShowEntityHistoryFilteredByChangedFieldWithRoleNotGranted(): void
    {
        $this->login(['ROLE_USER']);
        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author?field=fullname');
    }

    #[Depends('testShowEntityHistoryWithRoleNotGrantedForAuthorAuditViewing')]
    public function testShowTransactionHistoryAnonymously(): void
    {
        $this->login(['ROLE_1']);
        $reader = $this->createReader();

        /** @var Entry[] $audits */
        $audits = $reader->createQuery(Author::class)->execute();
        $first = $audits[\count($audits) - 1];

        $this->login();
        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->transactionId);

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check back link
        $backLink = $crawler->filter('a[href="/audit"]');
        $this->assertGreaterThanOrEqual(1, $backLink->count(), 'Back link to entities exists');

        // the action is told, then its technical details
        $this->assertStringContainsString((string) $first->transactionId, $crawler->filter('dl')->text(), 'Transaction ID is displayed');
        $this->assertGreaterThanOrEqual(1, $crawler->filter('h1')->count());
        $this->assertGreaterThanOrEqual(1, $crawler->filter('pre[id^="dh-auditor-json-"]')->count(), 'The raw diffs of its entries are shown');
    }

    #[Depends('testShowTransactionHistoryAnonymously')]
    public function testShowTransactionHistoryWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_1']);
        $reader = $this->createReader();

        /** @var Entry[] $audits */
        $audits = $reader->createQuery(Author::class)->execute();
        $first = $audits[\count($audits) - 1];

        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->transactionId);

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check back link
        $backLink = $crawler->filter('a[href="/audit"]');
        $this->assertGreaterThanOrEqual(1, $backLink->count(), 'Back link to entities exists');
    }

    #[DataProvider('provideFilterByPeriodCases')]
    public function testFilterByPeriod(array $filters, int $expectedCount): void
    {
        $this->seedPeriodEntries();
        $this->login(['ROLE_1']);
        // the period filter is a filter of the stream of an entity (the history of an object filters by last days)
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author', $filters);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(match ($expectedCount) {
            0 => 'no action', 1 => '1 action', default => $expectedCount.' actions'
        }, $crawler->filter('h1+p')->text());
        $this->assertSame($filters['date_from'] ?? '', $crawler->filter('[name="date_from"]')->attr('value'));
        $this->assertSame($filters['date_to'] ?? '', $crawler->filter('[name="date_to"]')->attr('value'));
    }

    public static function provideFilterByPeriodCases(): iterable
    {
        yield 'whole day including midnight and last second' => [['date_from' => '2026-02-10', 'date_to' => '2026-02-10'], 2];

        yield 'start only' => [['date_from' => '2026-02-10'], 3];

        yield 'end only' => [['date_to' => '2026-02-10'], 3];

        yield 'empty bounds' => [['date_from' => '', 'date_to' => ''], 4];

        yield 'no matches' => [['date_from' => '2026-02-12'], 0];

        yield 'combined filters' => [['date_from' => '2026-02-10', 'date_to' => '2026-02-10', 'type' => 'insert', 'user' => 'period-user'], 2];

        yield 'different action' => [['date_from' => '2026-02-10', 'type' => 'update'], 0];

        yield 'different user' => [['date_from' => '2026-02-10', 'user' => '__anonymous__'], 0];
    }

    #[DataProvider('provideRejectsInvalidPeriodCases')]
    public function testRejectsInvalidPeriod(array $filters): void
    {
        $this->login(['ROLE_1']);
        $this->client->catchExceptions(false);
        $this->expectException(BadRequestHttpException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author', $filters);
    }

    public static function provideRejectsInvalidPeriodCases(): iterable
    {
        yield 'invalid calendar date' => [['date_from' => '2026-02-30']];

        yield 'invalid end date' => [['date_to' => '2026-13-01']];

        yield 'relative date' => [['date_from' => 'yesterday']];

        yield 'non padded date' => [['date_from' => '2026-2-01']];

        yield 'null byte' => [['date_from' => "2026-02-10\0"]];

        yield 'array parameter' => [['date_from' => ['2026-02-10']]];

        yield 'reversed bounds' => [['date_from' => '2026-02-11', 'date_to' => '2026-02-10']];
    }

    public function testPeriodIsPreservedInPaginationAndForm(): void
    {
        $this->seedPeriodEntries();
        $this->provider->getConfiguration()->setViewerPageSize(1);
        $this->login(['ROLE_1']);
        $uri = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author';
        $filters = ['date_from' => '2026-02-10', 'date_to' => '2026-02-10', 'type' => 'insert', 'user' => 'period-user'];
        $crawler = $this->client->request('GET', $uri, $filters);

        $this->assertResponseIsSuccessful();
        $links = $crawler->filter('a[href*="page=2"]');
        $this->assertGreaterThan(0, $links->count());
        $url = $links->first()->attr('href');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        foreach ($filters as $name => $value) {
            $this->assertSame($value, $query[$name]);
        }

        $this->assertStringStartsWith($uri.'?', $url);
        $form = $crawler->selectButton('Apply')->form();
        foreach ($filters as $name => $value) {
            $this->assertSame($value, $form->getValues()[$name]);
        }

        $this->assertArrayNotHasKey('page', $form->getValues());
        $this->assertSame($uri, parse_url($form->getUri(), PHP_URL_PATH));

        $clearUrl = $crawler->selectLink('Clear period')->link()->getUri();
        parse_str(parse_url($clearUrl, PHP_URL_QUERY), $clearQuery);
        $this->assertSame(['type' => 'insert', 'user' => 'period-user'], $clearQuery);
    }

    #[DataProvider('provideActivityBarsLinkToTheirDayCases')]
    public function testActivityBarsLinkToTheirDay(string $layout): void
    {
        self::getContainer()->set(ViewerController::class, new ViewerController(
            self::getContainer()->get(Environment::class),
            new ActivityGraphProvider(7, $layout, false, 300),
        ));
        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit');
        $this->assertResponseIsSuccessful();

        $uri = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author';
        $bars = $crawler->filter('a[data-audit-date][href^="'.$uri.'?"]');
        $this->assertCount(7, $bars);
        $this->assertCount(1, $crawler->filter('a[href="'.$uri.'"]'));
        $today = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        foreach ($bars as $index => $bar) {
            $date = $today->modify('-'.(6 - $index).' days')->format('Y-m-d');
            $this->assertSame($date, $bar->getAttribute('data-audit-date'));
            parse_str(parse_url($bar->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame(['date_from' => $date, 'date_to' => $date], $query);
            $this->assertStringContainsString($date, (string) $bar->getAttribute('aria-label'));
        }

        $lastBar = $bars->last();
        $expectedCount = $lastBar->attr('data-audit-count');
        $this->assertGreaterThan(0, (int) $expectedCount);
        $filtered = $this->client->click($lastBar->link());
        $this->assertResponseIsSuccessful();
        // the bars count entries, the stream its actions
        $actions = $this->createReader()->createQuery(Author::class, ['page_size' => null])
            ->addFilter(new DateRangeFilter(Query::CREATED_AT, $today, $today->setTime(23, 59, 59)))
            ->countTransactions()
        ;
        $this->assertLessThanOrEqual((int) $expectedCount, $actions);
        $this->assertStringContainsString(1 === $actions ? '1 action' : $actions.' actions', $filtered->filter('h1+p')->text());
        $this->assertSame($today->format('Y-m-d'), $filtered->filter('[name="date_from"]')->attr('value'));
        $this->assertSame($today->format('Y-m-d'), $filtered->filter('[name="date_to"]')->attr('value'));

        $emptyDay = $this->client->click($bars->first()->link());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('no action', $emptyDay->filter('h1+p')->text());
    }

    public static function provideActivityBarsLinkToTheirDayCases(): iterable
    {
        yield 'bottom' => ['bottom'];

        yield 'inline' => ['inline'];
    }

    public function testShowUserTimelineMergesEntitiesChronologically(): void
    {
        $this->seedUserEntry(Post::class, 'timeline-user', 'old.name', '2026-01-01 10:00:00');
        $this->seedUserEntry(Comment::class, 'timeline-user', 'old.name', '2026-01-02 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'new.name', '2026-01-03 10:00:00');
        $this->seedUserEntry(Post::class, 'someone-else', 'someone.else', '2026-01-04 10:00:00');

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/user/timeline-user');

        $this->assertResponseIsSuccessful();
        $this->assertPageTitleContains('auditor');
        $this->assertStringContainsString('new.name', $crawler->filter('h1')->text(), 'The most recent username is shown');
        $this->assertStringContainsString('3 actions', $crawler->filter('h1+p')->text());
        $this->assertSame([Post::class, Comment::class, Post::class], $this->timelineEntities($crawler), 'Newest first, across entities');
    }

    /**
     * @param null|list<string> $roles            null means not logged in
     * @param list<string>      $expectedEntities
     */
    #[DataProvider('provideShowUserTimelineOnlyListsViewableEntitiesCases')]
    public function testShowUserTimelineOnlyListsViewableEntities(?array $roles, array $expectedEntities): void
    {
        $this->seedUserEntry(Author::class, 'timeline-user', 'timeline.user', '2026-01-02 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-01 10:00:00');

        if (null !== $roles) {
            $this->login($roles);
        }

        $crawler = $this->client->request('GET', '/audit/user/timeline-user');

        $this->assertResponseIsSuccessful();
        $this->assertSame($expectedEntities, $this->timelineEntities($crawler));
        $this->assertStringContainsString(\count($expectedEntities).' action', $crawler->filter('h1+p')->text(), 'Denied entities are not counted');
    }

    /**
     * @return iterable<string, array{null|list<string>, list<string>}>
     */
    public static function provideShowUserTimelineOnlyListsViewableEntitiesCases(): iterable
    {
        yield 'anonymous' => [null, [Post::class]];

        yield 'role not granted for Author' => [['ROLE_USER'], [Post::class]];

        yield 'role granted for Author' => [['ROLE_1'], [Author::class, Post::class]];
    }

    /**
     * @param list<string> $expectedEntities
     */
    #[DataProvider('provideShowUserTimelinePaginatesAcrossEntitiesCases')]
    public function testShowUserTimelinePaginatesAcrossEntities(int $page, array $expectedEntities, bool $hasNextPage): void
    {
        $this->seedUserEntry(Comment::class, 'timeline-user', 'timeline.user', '2026-01-01 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-02 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-03 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-04 10:00:00');
        $this->provider->getConfiguration()->setViewerPageSize(2);

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/user/timeline-user', ['page' => $page]);

        $this->assertResponseIsSuccessful();
        $this->assertSame($expectedEntities, $this->timelineEntities($crawler));
        $this->assertStringContainsString('4 actions', $crawler->filter('h1+p')->text());
        $this->assertSame($hasNextPage, $crawler->filter('a[data-dh-auditor-more][href^="/audit/user/timeline-user?page=2"]')->count() > 0);
    }

    /**
     * @return iterable<string, array{int, list<string>, bool}>
     */
    public static function provideShowUserTimelinePaginatesAcrossEntitiesCases(): iterable
    {
        yield 'first page' => [1, [Post::class, Post::class], true];

        // the oldest Post row is beyond the first page of its own entity
        yield 'last page' => [2, [Post::class, Comment::class], false];

        yield 'out of range page falls back to the last one' => [99, [Post::class, Comment::class], false];
    }

    /**
     * @param list<string> $expectedEntities
     */
    #[DataProvider('provideShowUserTimelineOrdersSimultaneousEntriesByIdCases')]
    public function testShowUserTimelineOrdersSimultaneousEntriesById(int $postId, int $commentId, array $expectedEntities): void
    {
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-01 10:00:00', null, $postId);
        $this->seedUserEntry(Comment::class, 'timeline-user', 'timeline.user', '2026-01-01 10:00:00', null, $commentId);

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/user/timeline-user');

        $this->assertResponseIsSuccessful();
        $this->assertSame($expectedEntities, $this->timelineEntities($crawler), 'Same instant: newest id first, whatever the entity');
    }

    /**
     * @return iterable<string, array{int, int, list<string>}>
     */
    public static function provideShowUserTimelineOrdersSimultaneousEntriesByIdCases(): iterable
    {
        yield 'post written last' => [1_001, 1_000, [Post::class, Comment::class]];

        yield 'comment written last' => [1_000, 1_001, [Comment::class, Post::class]];
    }

    public function testShowTransactionListsEveryEntry(): void
    {
        $reader = $this->createReader();
        $this->login(['ROLE_1']);

        // the transaction with the most entries across entities
        $sizes = [];
        foreach ([Author::class, Post::class, Comment::class, Tag::class] as $entity) {
            foreach ($reader->createQuery($entity, ['page_size' => null])->execute() as $entry) {
                $sizes[$entry->transactionId] = ($sizes[$entry->transactionId] ?? 0) + 1;
            }
        }

        arsort($sizes);
        $transactionId = (string) array_key_first($sizes);
        $total = $sizes[$transactionId];
        $this->assertGreaterThan(2, $total, 'The fixture has a transaction of several entries');

        $crawler = $this->client->request('GET', '/audit/transaction/'.$transactionId);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($total.' entries', $crawler->filter('dl')->text(), 'The details count the whole action');
        $this->assertCount($total, $crawler->filter('pre[id^="dh-auditor-json-"]'), 'Every entry is shown');
    }

    public function testTheTechnicalDetailsOfAnActionHaveFixedColumns(): void
    {
        $this->login(['ROLE_1']);
        $first = $this->createReader()->createQuery(Post::class)->execute()[0];

        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->transactionId);

        $this->assertResponseIsSuccessful();
        $table = $crawler->filter('section table')->first();
        $this->assertStringContainsString('table-fixed', (string) $table->attr('class'), 'The columns do not depend on the values of the action.');
        $this->assertStringContainsString('text-dh-foreground', (string) $table->attr('class'), 'The values take the foreground token, readable in both themes.');
        $this->assertSame(
            ['w-[14%]', 'w-[9%]', 'w-[13%]', 'w-[14%]', 'w-1/4', 'w-1/4'],
            $table->filter('colgroup > col')->each(static fn (Crawler $col): string => (string) $col->attr('class')),
            'The old and new values get the same room.',
        );
    }

    public function testTheTechnicalDetailsPutTheTargetOfALinkUnderNewOrOld(): void
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Title')->setBody('Body')->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
        $tag = new Tag()->setTitle('linked tag');
        $entityManager->persist($post);
        $entityManager->persist($tag);
        $entityManager->flush();

        $post->addTag($tag);
        $entityManager->flush();
        $post->removeTag($tag);
        $entityManager->flush();

        $this->login();
        foreach (['associate' => [4, 5], 'dissociate' => [5, 4]] as $type => [$empty, $target]) {
            $entry = $this->createReader()->createQuery(Post::class, ['object_id' => (string) $post->getId(), 'type' => $type])->execute()[0];
            $crawler = $this->client->request('GET', '/audit/transaction/'.$entry->transactionId);

            $this->assertResponseIsSuccessful();
            $cells = $crawler->filter('section table tbody tr')->first()->filter('td');
            $this->assertCount(6, $cells, $type.': one cell per column, none spanning old and new');
            $this->assertSame('—', mb_trim($cells->eq($empty)->text()), $type.': the other side of the link is empty');
            $this->assertStringContainsString('linked tag', $cells->eq($target)->text(), $type.': the target sits under '.('associate' === $type ? 'new' : 'old'));
        }
    }

    public function testViewerHasNoInlineScript(): void
    {
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-01 10:00:00');
        $this->login(['ROLE_1']);

        $reader = $this->createReader();
        $first = $reader->createQuery(Post::class)->execute()[0];
        $revisions = $this->revisionIds(Post::class, (int) $first->objectId);
        $object = \sprintf('%s/%s', self::POST_URL, $first->objectId);

        foreach ([
            '/audit',
            self::POST_URL,
            $object,
            '/audit/user/timeline-user',
            '/audit/transaction/'.$first->transactionId.'?root='.urlencode(Post::class.':'.$first->objectId),
            $object.'/revisions',
            \sprintf('%s/compare?from=%d&to=%d', $object, $revisions[0], $revisions[\count($revisions) - 1]),
        ] as $url) {
            $crawler = $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful();

            $html = (string) $this->client->getResponse()->getContent();
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, $url.': no event handler attribute');
            $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, $url.': no style attribute');
            $this->assertCount(0, $crawler->filter('script:not([src])'), $url.': no inline script');
        }
    }

    public function testObjectHistory(): void
    {
        [$postId] = $this->createPostHistory();
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));

        $this->assertResponseIsSuccessful();
        $this->assertSame('Title C', $crawler->filter('h1')->text(), 'The title is the label of the object.');
        $this->assertStringContainsString('3 actions', $crawler->filter('h1 + p')->text());
        $this->assertCount(3, $crawler->filter('[data-dh-auditor-action]'), 'One row per action.');
        $this->assertStringContainsString('created Post', $crawler->filter('[data-dh-auditor-action]')->last()->text());
        $this->assertSelectorTextContains('aside', 'Who changed what last');
        $this->assertSelectorTextContains('aside', 'createRootQuery(Post, '.$postId.')');

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId), ['what' => 'insert']);
        $this->assertCount(1, $crawler->filter('[data-dh-auditor-action]'), 'Filtered by type.');
        $this->assertSame('insert', $crawler->filter('select[name="what"] option[selected]')->attr('value'));

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId), ['origin' => 'console']);
        $this->assertCount(0, $crawler->filter('[data-dh-auditor-action]'), 'No change was made by a command.');
        $this->assertSelectorTextContains('body', 'No action.');

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId), ['when' => '7']);
        $this->assertCount(3, $crawler->filter('[data-dh-auditor-action]'), 'Written today.');

        $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET created_at = ? WHERE object_id = ?', $this->createReader()->getEntityAuditTableName(Post::class)),
            ['2020-01-01 00:00:00', (string) $postId],
        );
        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId), ['when' => '7']);
        $this->assertCount(0, $crawler->filter('[data-dh-auditor-action]'), 'Older than 7 days.');
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('provideObjectHistoryRejectsUnknownFiltersCases')]
    public function testObjectHistoryRejectsUnknownFilters(array $query): void
    {
        [$postId] = $this->createPostHistory();
        $this->login();

        $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId), $query);

        $this->assertResponseStatusCodeSame(400);
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function provideObjectHistoryRejectsUnknownFiltersCases(): iterable
    {
        yield 'type' => [['what' => 'merge']];

        yield 'origin' => [['origin' => 'api']];

        yield 'period' => [['when' => '12']];

        yield 'period with trailing text' => [['when' => '7abc']];

        yield 'cursor' => [['after' => 'not-a-cursor']];
    }

    public function testOlderActionsAreLoadedAsAFragment(): void
    {
        [$postId] = $this->createPostHistory();
        $this->provider->getConfiguration()->setViewerPageSize(1);
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));
        $this->assertCount(1, $crawler->filter('[data-dh-auditor-action]'));
        $more = $crawler->filter('a[data-dh-auditor-more]');
        $this->assertCount(1, $more, 'A link to the older actions.');

        $fragment = $this->client->request('GET', (string) $more->attr('href'), [], [], ['HTTP_X_DH_AUDITOR_FRAGMENT' => '1']);
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent(), 'Only the rows.');
        $this->assertCount(1, $fragment->filter('[data-dh-auditor-action]'));
        $this->assertCount(0, $fragment->filter('h3'), 'The day the previous page ended on is not repeated.');
        $this->assertCount(1, $fragment->filter('a[data-dh-auditor-more]'), 'Then a link to the next ones.');

        $page = $this->client->request('GET', (string) $more->attr('href'));
        $this->assertStringContainsString('<html', (string) $this->client->getResponse()->getContent(), 'Without the header, the link opens a whole page.');
        $this->assertCount(1, $page->filter('[data-dh-auditor-action]'));

        $last = $this->client->request('GET', (string) $fragment->filter('a[data-dh-auditor-more]')->attr('href'), [], [], ['HTTP_X_DH_AUDITOR_FRAGMENT' => '1']);
        $this->assertCount(1, $last->filter('[data-dh-auditor-action]'), 'The oldest action.');
        $this->assertCount(0, $last->filter('a[data-dh-auditor-more]'), 'Nothing older.');
    }

    public function testObjectHistoryNeedsTheViewRole(): void
    {
        $this->login(['ROLE_USER']);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author/1');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeletedObjectsAreMarked(): void
    {
        [$postId] = $this->createPostHistory();
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = $entityManager->find(Post::class, $postId);
        $this->assertInstanceOf(Post::class, $post);
        $entityManager->remove($post);
        $entityManager->flush();
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));

        $actions = $crawler->filter('[data-dh-auditor-action]');
        $deletion = $actions->first()->text();
        $this->assertStringContainsString('deleted', $deletion);
        $this->assertStringNotContainsString('(deleted)', $deletion, 'The phrase of the deletion already says it.');
        $this->assertStringContainsString('(deleted)', $actions->last()->text(), 'An earlier action marks the post that no longer exists.');
    }

    public function testUserActionsFilters(): void
    {
        $this->seedUserEntry(Comment::class, 'timeline-user', 'timeline.user', '2020-01-01 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', new \DateTimeImmutable('-1 day')->format('Y-m-d H:i:s'));
        $this->login(['ROLE_1']);

        $crawler = $this->client->request('GET', '/audit/user/timeline-user', ['on' => Post::class]);
        $this->assertSame([Post::class], $this->timelineEntities($crawler), 'On one entity.');

        $crawler = $this->client->request('GET', '/audit/user/timeline-user', ['when' => '7']);
        $this->assertSame([Post::class], $this->timelineEntities($crawler), 'In the last days.');

        $crawler = $this->client->request('GET', '/audit/user/timeline-user');
        $this->assertSame([Post::class, Comment::class], $this->timelineEntities($crawler));
    }

    public function testActionLinksToItsNeighboursInTheHistoryItWasOpenedFrom(): void
    {
        [$postId] = $this->createPostHistory();
        $this->login();
        $actions = array_values(array_unique(array_map(
            static fn (Entry $entry): ?string => $entry->transactionId,
            $this->createReader()->createQuery(Post::class, ['object_id' => $postId, 'page_size' => null])->execute(),
        )));
        $this->assertCount(3, $actions, 'Newest first.');
        $root = urlencode(Post::class.':'.$postId);

        $crawler = $this->client->request('GET', '/audit/transaction/'.$actions[1].'?root='.$root);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/audit/transaction/'.$actions[2], (string) $crawler->filter('a[rel="prev"]')->attr('href'), 'The previous (older) action.');
        $this->assertStringContainsString('/audit/transaction/'.$actions[0], (string) $crawler->filter('a[rel="next"]')->attr('href'), 'The next (newer) action.');
        $this->assertStringContainsString('Post #'.$postId, $crawler->filter('nav[aria-label="breadcrumb"]')->text());

        $crawler = $this->client->request('GET', '/audit/transaction/'.$actions[1]);
        $this->assertCount(0, $crawler->filter('a[rel="prev"], a[rel="next"]'), 'No neighbours without the history it was opened from.');

        $this->client->request('GET', '/audit/transaction/unknown-transaction');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testOverview(): void
    {
        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Overview');
        $recent = $crawler->filter('section')->first()->filter('[data-dh-auditor-action]');
        $this->assertGreaterThanOrEqual(1, $recent->count(), 'The recent activity lists actions.');
        $this->assertLessThanOrEqual(10, $recent->count());

        $reader = $this->createReader();
        $actions = \count(array_unique(array_map(static fn (Entry $entry): ?string => $entry->transactionId, $reader->createQuery(Post::class, ['page_size' => null])->execute())));
        $row = $crawler->filter('tr[data-dh-auditor-entity]')->reduce(static fn (Crawler $tr): bool => Post::class === $tr->attr('data-dh-auditor-entity'));
        $this->assertSame((string) $actions, mb_trim($row->filter('td')->eq(1)->text()), 'Entities count their actions.');
    }

    public function testLegacyEntriesDoNotBreakThePages(): void
    {
        $table = $this->createReader()->getEntityAuditTableName(Post::class);
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM '.$table." WHERE type = 'insert' ORDER BY id LIMIT 1");
        $this->assertIsArray($row);
        unset($row['id']);
        $hash = sha1('legacy');
        $connection->insert($table, [
            'schema_version' => 1,
            'diffs' => json_encode(['title' => ['old' => null, 'new' => 'Legacy title']], JSON_THROW_ON_ERROR),
            'transaction_id' => $hash,
            'blame_id' => 'legacy-user',
            'blame' => json_encode(['username' => 'legacy.user'], JSON_THROW_ON_ERROR),
            'created_at' => '2026-01-01 10:00:00',
        ] + $row);
        $this->login(['ROLE_1']);

        foreach (['/audit', self::POST_URL, '/audit/user/legacy-user', '/audit/transaction/'.$hash] as $url) {
            $this->client->request('GET', $url);
            $this->assertResponseIsSuccessful($url.' reads entries of the schema v1.');
        }

        $this->assertStringNotContainsString('ULID', (string) $this->client->getResponse()->getContent(), 'A SHA1 transaction has no ULID date.');
    }

    public function testActionOfALegacyTransactionInAHistory(): void
    {
        [$postId] = $this->createPostHistory();
        $actions = array_values(array_unique(array_map(
            static fn (Entry $entry): ?string => $entry->transactionId,
            $this->createReader()->createQuery(Post::class, ['object_id' => $postId, 'page_size' => null])->execute(),
        )));
        $hash = sha1('migrated from 7.x');
        $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection()->executeStatement(
            \sprintf('UPDATE %s SET transaction_id = ? WHERE transaction_id = ?', $this->createReader()->getEntityAuditTableName(Post::class)),
            [$hash, $actions[1]],
        );
        $this->login();
        $root = urlencode(Post::class.':'.$postId);

        $crawler = $this->client->request('GET', '/audit/transaction/'.$hash.'?root='.$root);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/audit/transaction/'.$actions[2], (string) $crawler->filter('a[rel="prev"]')->attr('href'));
        $this->assertStringContainsString('/audit/transaction/'.$actions[0], (string) $crawler->filter('a[rel="next"]')->attr('href'));

        $crawler = $this->client->request('GET', '/audit/transaction/'.$actions[0].'?root='.$root);
        $this->assertStringContainsString('/audit/transaction/'.$hash, (string) $crawler->filter('a[rel="prev"]')->attr('href'));
    }

    public function testRecentActivityIsTheLatestActionsAcrossEntities(): void
    {
        $this->seedUserEntry(Comment::class, 'u', 'u', '2030-01-01 10:00:00');
        $this->seedUserEntry(Post::class, 'u', 'u', '2030-01-02 10:00:00');
        $this->seedUserEntry(Tag::class, 'u', 'u', '2030-01-03 10:00:00', transactionId: 'one-action');
        $this->seedUserEntry(Post::class, 'u', 'u', '2030-01-03 10:00:00', transactionId: 'one-action');
        $this->login(['ROLE_1']);

        $crawler = $this->client->request('GET', '/audit');
        $actions = $crawler->filter('section')->first()->filter('[data-dh-auditor-entities]')->each(static fn (Crawler $action): string => (string) $action->attr('data-dh-auditor-entities'));

        $entities = static fn (string $list): array => (static function (array $classes): array {
            sort($classes);

            return $classes;
        })(explode(' ', $list));
        $this->assertSame([Post::class, Tag::class], $entities($actions[0]), 'An action written in two tables is one row, the most recent first.');
        $this->assertSame([Post::class], $entities($actions[1]));
        $this->assertSame([Comment::class], $entities($actions[2]));
        $link = $crawler->filter('section')->first()->filter('[data-dh-auditor-action] a[href^="/audit/transaction/"]')->first();
        $this->assertCount(1, $link, 'The time of a row links to its action.');
        $this->assertStringStartsWith('Action of ', (string) $link->attr('aria-label'), 'The link of the time is named after the action.');
        $this->assertContains('after:inset-0', explode(' ', (string) $link->attr('class')), 'The link of the time stretches over the whole row.');
        $this->assertStringContainsString('relative', (string) $link->closest('[data-dh-auditor-action]')?->attr('class'), 'The row bounds the stretched link.');
    }

    public function testRecentActivityTellsTheLatestActionsWhole(): void
    {
        $this->seedAction(Comment::class, 'import', '2030-01-02 10:00:00', range(1_001, 1_025));
        $this->seedAction(Post::class, 'import', '2030-01-02 10:00:00', [1_001]);
        $this->login(['ROLE_1']);

        $crawler = $this->client->request('GET', '/audit');
        $import = $crawler->filter('section')->first()->filter('[data-dh-auditor-action="import"]');

        $this->assertCount(1, $import);
        $this->assertStringContainsString('created 25 Comment', $import->text(), 'More entries than read per table: the action is read whole.');
    }

    public function testEntityHistoryPagesHoldActions(): void
    {
        $this->seedAction(Comment::class, 'import', '2030-01-03 10:00:00', range(1_001, 1_006));
        $this->seedAction(Comment::class, 'alone', '2030-01-02 10:00:00', [1_007]);
        $this->seedAction(Comment::class, 'pair', '2030-01-01 10:00:00', [1_008, 1_009]);
        $this->provider->getConfiguration()->setViewerPageSize(2);
        $this->login(['ROLE_1']);

        $url = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Comment';
        $actions = $this->createReader()->createQuery(Comment::class, ['page_size' => null])->countTransactions();
        $first = $this->client->request('GET', $url);
        $this->assertSame(['import', 'alone'], $this->actionKeys($first), 'A page holds page_size actions, whatever their number of entries.');
        $this->assertStringContainsString('created 6 Comment', $first->filter('[data-dh-auditor-action="import"]')->text());
        $this->assertStringContainsString($actions.' actions', $first->filter('h1+p')->text(), 'The header counts the actions.');
        $this->assertGreaterThan(0, $first->filter('a[href*="page='.(int) ceil($actions / 2).'"]')->count(), 'The pager counts pages of actions.');
        $this->assertCount(0, $first->filter('a[href*="page='.((int) ceil($actions / 2) + 1).'"]'));

        $second = $this->client->request('GET', $url, ['page' => 2]);
        $this->assertSame('pair', $this->actionKeys($second)[0] ?? null);
        $this->assertCount(2, $this->actionKeys($second));

        $beyond = $this->client->request('GET', $url, ['page' => 99]);
        $this->assertNotSame([], $this->actionKeys($beyond), 'A page beyond the last one shows the last one.');
    }

    public function testEntityHistoryReadsTheEntriesOfAnActionUpToALimit(): void
    {
        $this->seedAction(Comment::class, 'import', '2030-01-03 10:00:00', range(1_001, 1_000 + WholeActions::MAX_ENTRIES + 5));
        $this->seedAction(Comment::class, 'alone', '2030-01-02 10:00:00', [9_999]);
        $this->provider->getConfiguration()->setViewerPageSize(2);
        $this->login(['ROLE_1']);

        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Comment');

        $this->assertSame(['import', 'alone'], $this->actionKeys($crawler), 'A very large action does not push the next one out of its page.');
        $this->assertStringContainsString('created '.(WholeActions::MAX_ENTRIES - 1).' Comment', $crawler->filter('[data-dh-auditor-action="import"]')->text());
        $this->assertStringContainsString('object 9999', $crawler->filter('[data-dh-auditor-action="alone"]')->text());
    }

    public function testAPageOfActionsReadsAtMostALimitOfEntries(): void
    {
        $half = intdiv(WholeActions::MAX_ENTRIES, 2);
        $this->seedAction(Comment::class, 'first', '2030-01-03 10:00:00', range(1_001, 1_000 + $half + 100));
        $this->seedAction(Comment::class, 'second', '2030-01-02 10:00:00', range(5_001, 5_000 + $half + 100));
        $this->seedAction(Comment::class, 'small', '2030-01-01 10:00:00', range(9_001, 9_010));
        $this->provider->getConfiguration()->setViewerPageSize(3);
        $this->login(['ROLE_1']);

        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Comment');

        $this->assertSame(['first', 'second', 'small'], $this->actionKeys($crawler));
        $share = intdiv(WholeActions::MAX_ENTRIES - 10, 2);
        $this->assertStringContainsString('created '.$share.' Comment', $crawler->filter('[data-dh-auditor-action="first"]')->text(), 'The large actions share what the small ones leave of the page.');
        $this->assertStringContainsString('created '.$share.' Comment', $crawler->filter('[data-dh-auditor-action="second"]')->text());
        $this->assertStringContainsString('created 10 Comment', $crawler->filter('[data-dh-auditor-action="small"]')->text(), 'A small action is told whole.');
    }

    public function testATableBeingMigratedKeepsTheActionsOfItsRowsNotConvertedYet(): void
    {
        $this->seedAction(Comment::class, 'converted', '2030-01-03 10:00:00', [9_001, 9_002]);
        $this->seedAction(Comment::class, 'legacy', '2030-01-02 10:00:00', [9_003, 9_004, 9_005]);
        $this->seedAction(Comment::class, 'older', '2030-01-01 10:00:00', [9_006]);
        // audit:schema:migrate without --convert-transaction-hash: transaction_hash kept, transaction_id NULL
        $table = $this->createReader()->getEntityAuditTableName(Comment::class);
        $connection = $this->provider->getStorageServiceForEntity(Comment::class)->getEntityManager()->getConnection();
        $connection->executeStatement(\sprintf('ALTER TABLE %s ADD transaction_hash VARCHAR(40) NULL', $table));
        $connection->executeStatement(\sprintf("UPDATE %s SET transaction_hash = transaction_id, transaction_id = NULL WHERE transaction_id = 'legacy'", $table));
        $this->provider->getConfiguration()->setViewerPageSize(2);
        $this->login(['ROLE_1']);

        $stream = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Comment');
        $this->assertSame(['converted', 'legacy'], $this->actionKeys($stream), 'The rows not converted yet are one action, by their hash.');
        $this->assertStringContainsString('created 3 Comment', $stream->filter('[data-dh-auditor-action="legacy"]')->text());

        $overview = $this->client->request('GET', '/audit');
        $this->assertStringContainsString('created 3 Comment', $overview->filter('[data-dh-auditor-action="legacy"]')->text());
    }

    public function testAnEntryWithoutTransactionIsAnActionOfItsOwn(): void
    {
        $this->seedUserEntry(Comment::class, 'lonely', 'lonely', '2030-01-02 10:00:00', null, 9_001);
        $this->seedUserEntry(Comment::class, 'lonely', 'lonely', '2030-01-02 10:00:00', null, 9_002);
        $this->seedAction(Comment::class, 'next', '2030-01-01 10:00:00', [9_003]);
        $connection = $this->provider->getStorageServiceForEntity(Comment::class)->getEntityManager()->getConnection();
        $connection->executeStatement(\sprintf('UPDATE %s SET transaction_id = NULL WHERE id IN (9001, 9002)', $this->createReader()->getEntityAuditTableName(Comment::class)));
        $this->provider->getConfiguration()->setViewerPageSize(2);
        $this->login(['ROLE_1']);

        $stream = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Comment');
        $this->assertSame([Comment::class.'#9002', Comment::class.'#9001'], $this->actionKeys($stream));

        $user = $this->client->request('GET', '/audit/user/lonely');
        $this->assertSame([Comment::class.'#9002', Comment::class.'#9001'], $this->actionKeys($user));
        $this->assertStringContainsString('2 actions', $user->filter('h1+p')->text());

        $overview = $this->client->request('GET', '/audit');
        $this->assertSame([Comment::class.'#9002', Comment::class.'#9001', 'next'], \array_slice($this->actionKeys($overview->filter('section')->first()), 0, 3));
    }

    public function testUserTimelinePagesHoldActions(): void
    {
        $this->seedAction(Comment::class, 'import', '2030-01-03 10:00:00', range(1_001, 1_006), 'timeline-user');
        $this->seedAction(Post::class, 'import', '2030-01-03 10:00:00', [1_001], 'timeline-user');
        $this->seedAction(Comment::class, 'alone', '2030-01-02 10:00:00', [1_007], 'timeline-user');
        $this->seedAction(Post::class, 'last', '2030-01-01 10:00:00', [1_002], 'timeline-user');
        $this->provider->getConfiguration()->setViewerPageSize(2);
        $this->login(['ROLE_1']);

        $first = $this->client->request('GET', '/audit/user/timeline-user');
        $this->assertSame(['import', 'alone'], $this->actionKeys($first));
        $this->assertStringContainsString('created 6 Comment', $first->filter('[data-dh-auditor-action="import"]')->text());
        $this->assertStringContainsString('3 actions', $first->filter('h1+p')->text(), 'An action on two tables is one action.');
        $this->assertCount(1, $first->filter('a[data-dh-auditor-more][href^="/audit/user/timeline-user?page=2"]'));

        $second = $this->client->request('GET', '/audit/user/timeline-user', ['page' => 2]);
        $this->assertSame(['last'], $this->actionKeys($second));
        $this->assertCount(0, $second->filter('a[data-dh-auditor-more]'));
    }

    public function testObjectHistoryPagesHoldActions(): void
    {
        [$postId, $revisions] = $this->createPostHistory();
        // the two updates made one action of two entries
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $table = $this->createReader()->getEntityAuditTableName(Post::class);
        $connection->executeStatement(\sprintf('UPDATE %1$s SET transaction_id = (SELECT transaction_id FROM %1$s WHERE id = ?), created_at = (SELECT created_at FROM %1$s WHERE id = ?) WHERE id = ?', $table), [$revisions[2], $revisions[2], $revisions[1]]);
        $this->provider->getConfiguration()->setViewerPageSize(2);
        $this->login();

        $page = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));
        $this->assertCount(2, $page->filter('[data-dh-auditor-action]'), 'Two actions per page, the first one of two entries.');
        $this->assertCount(0, $page->filter('a[data-dh-auditor-more]'));
        $this->assertStringContainsString('2 actions', $page->filter('h1 + p')->text());
        $this->assertStringContainsString('3 entries', $page->filter('aside')->text(), 'The technical side counts the entries.');
    }

    public function testDaysAcrossOlderActions(): void
    {
        [$postId] = $this->createPostHistory();
        $table = $this->createReader()->getEntityAuditTableName(Post::class);
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $yesterday = new \DateTimeImmutable('yesterday 10:00', new \DateTimeZone('UTC'));
        $oldest = $this->revisionIds(Post::class, $postId);
        $connection->executeStatement(\sprintf('UPDATE %s SET created_at = ? WHERE id IN (?, ?)', $table), [$yesterday->format('Y-m-d H:i:s'), $oldest[0], $oldest[1]]);
        $this->provider->getConfiguration()->setViewerPageSize(1);
        $this->login();

        $page = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));
        $this->assertSame(['Today'], $page->filter('h3')->each(static fn (Crawler $h3): string => $h3->text()));
        $more = (string) $page->filter('a[data-dh-auditor-more]')->attr('href');
        $this->assertStringContainsString('day='.date('Y-m-d'), $more, 'The day the page ended on.');

        $first = $this->client->request('GET', $more, [], [], ['HTTP_X_DH_AUDITOR_FRAGMENT' => '1']);
        $this->assertSame(['Yesterday'], $first->filter('h3')->each(static fn (Crawler $h3): string => $h3->text()), 'A new day.');
        $next = (string) $first->filter('a[data-dh-auditor-more]')->attr('href');
        $this->assertStringContainsString('day='.$yesterday->format('Y-m-d'), $next);

        $second = $this->client->request('GET', $next, [], [], ['HTTP_X_DH_AUDITOR_FRAGMENT' => '1']);
        $this->assertCount(0, $second->filter('h3'), 'The same day goes on.');
        $this->assertCount(1, $second->filter('[data-dh-auditor-action]'));
    }

    public function testShortPhraseOnSmallScreens(): void
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('A')->setBody('a')->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($post);
        $entityManager->flush();
        $post->setTitle('B')->setBody('b')->setCreatedAt(new \DateTimeImmutable('2026-01-02'))->setDeletedAt(new \DateTimeImmutable('2026-01-03'));
        $entityManager->flush();
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, (int) $post->getId()));
        $row = $crawler->filter('[data-dh-auditor-action]')->first();

        $this->assertStringContainsString('1 other change', $row->filterXPath('.//span[contains(concat(" ", @class, " "), " sm:hidden ")]')->text(), 'Three fragments on a small screen.');
        $this->assertStringNotContainsString('other change', $row->filterXPath('.//span[contains(concat(" ", @class, " "), " sm:inline ")]')->text(), 'The whole phrase otherwise.');
    }

    public function testUserPageFilters(): void
    {
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-01 10:00:00');
        $this->seedUserEntry(Post::class, 'timeline-user', 'timeline.user', '2026-01-02 10:00:00');
        $this->provider->getConfiguration()->setViewerPageSize(1);
        $this->login(['ROLE_1']);

        $this->client->request('GET', '/audit/user/timeline-user', ['on' => 'App\Entity\Unknown']);
        $this->assertResponseStatusCodeSame(400);

        $crawler = $this->client->request('GET', '/audit/user/timeline-user', ['on' => Post::class, 'who' => 'x', 'field' => 'title']);
        parse_str((string) parse_url((string) $crawler->filter('a[data-dh-auditor-more]')->attr('href'), PHP_URL_QUERY), $query);
        $this->assertSame(['day', 'on', 'page'], (static function (array $keys): array {
            sort($keys);

            return $keys;
        })(array_keys($query)), 'Only the filters of the page are carried on.');
    }

    public function testActionIgnoresARootThatDoesNotHoldIt(): void
    {
        [$postId] = $this->createPostHistory();
        [$otherId] = $this->createPostHistory();
        $action = $this->createReader()->createQuery(Post::class, ['object_id' => $postId, 'page_size' => 1])->execute()[0];
        $this->login();

        $crawler = $this->client->request('GET', '/audit/transaction/'.$action->transactionId.'?root='.urlencode(Post::class.':'.$otherId));
        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('a[rel="prev"], a[rel="next"]'));
        $this->assertStringNotContainsString('#'.$otherId, $crawler->filter('nav[aria-label="breadcrumb"]')->text(), 'Not the history of another post.');

        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit/transaction/'.$action->transactionId.'?root='.urlencode(Author::class.':1'));
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Author', $crawler->filter('nav[aria-label="breadcrumb"]')->text(), 'Not a history the reader may not view.');
    }

    public function testAuthorFilterKeepsAnAuthorLeftOutOfTheList(): void
    {
        [$postId] = $this->createPostHistory();
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId), ['who' => 'someone-else']);

        $this->assertSame('someone-else', $crawler->filter('select[name="who"] option[selected]')->attr('value'));
    }

    public function testShowUserTimelineWithoutMatches(): void
    {
        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/user/does-not-exist');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('does-not-exist', $crawler->filter('h1')->text());
        $this->assertStringContainsString('No action.', $crawler->filter('body')->text());
    }

    public function testShowUserTimelineRejectsTooLongIdentifier(): void
    {
        $this->login(['ROLE_1']);
        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', '/audit/user/'.str_repeat('a', 256));
    }

    /**
     * A user who wrote entries from several IPs, or under several usernames, is listed once in the
     * user filter, under their latest username; users are sorted by name.
     */
    public function testUserFilterListsEachUserOnceUnderTheirLatestName(): void
    {
        $this->seedUserEntry(Post::class, 'filter-user', 'old.name', '2026-01-01 10:00:00', '10.0.0.1');
        $this->seedUserEntry(Post::class, 'filter-user', 'new.name', '2026-01-02 10:00:00', '10.0.0.2');
        $this->seedUserEntry(Post::class, 'filter-user', 'new.name', '2026-01-03 10:00:00', '10.0.0.3');
        $this->seedUserEntry(Post::class, 'other-user', 'alice', '2026-01-04 10:00:00', '10.0.0.4');

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post');

        $this->assertResponseIsSuccessful();
        $options = $crawler->filter('#filter-user option')->each(
            static fn (Crawler $option): array => [(string) $option->attr('value'), mb_trim($option->text())]
        );
        $seeded = array_values(array_filter($options, static fn (array $option): bool => \in_array($option[0], ['filter-user', 'other-user'], true)));

        $this->assertSame([['other-user', 'alice'], ['filter-user', 'new.name']], $seeded, 'One option per user, latest username, sorted by name');

        $names = array_column(array_filter($options, static fn (array $option): bool => !\in_array($option[0], ['', '__anonymous__'], true)), 1);
        $sorted = $names;
        usort($sorted, static fn (string $a, string $b): int => mb_strtolower($a) <=> mb_strtolower($b));
        $this->assertSame($sorted, $names, 'Users are sorted by name');
    }

    /**
     * Entries link their author to the user timeline only when they carry a usable blame_id:
     * users without a getId() method are recorded with an empty blame_id, and entries may hold a
     * username without any blame_id.
     */
    public function testEntriesLinkTheirAuthorToTheUserTimeline(): void
    {
        $this->seedUserEntry(Post::class, 'team/42', 'slashed.user', '2026-01-01 10:00:00');
        $this->seedUserEntry(Post::class, '', 'in.memory.user', '2026-01-02 10:00:00');
        $this->seedUserEntry(Post::class, null, 'unidentified.user', '2026-01-03 10:00:00');

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post');

        $this->assertResponseIsSuccessful();
        $this->assertSame('slashed.user', $crawler->filter('a[href="/audit/user/team/42"]')->first()->text());
        $this->assertCount(0, $crawler->filter('a[href="/audit/user/"]'), 'No link for an empty blame_id');
        $this->assertCount(0, $crawler->selectLink('in.memory.user'));
        $this->assertCount(0, $crawler->selectLink('unidentified.user'));
        $this->assertStringContainsString('in.memory.user', $crawler->filter('body')->text());
        $this->assertStringContainsString('unidentified.user', $crawler->filter('body')->text());

        $crawler = $this->client->click($crawler->selectLink('slashed.user')->link());

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('slashed.user', $crawler->filter('h1')->text());
        $this->assertSame([Post::class], $this->timelineEntities($crawler));
    }

    public function testEntriesShowTheirBlameContext(): void
    {
        $table = $this->createReader()->getEntityAuditTableName(Post::class);
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM '.$table." WHERE type = 'insert' ORDER BY id LIMIT 1");
        $this->assertIsArray($row);
        unset($row['id']);
        $row['blame'] = json_encode([
            'username' => 'importer',
            'via' => ['type' => 'import', 'label' => 'Import batch #42', 'data' => ['file' => 'posts.csv']],
        ], JSON_THROW_ON_ERROR);
        $row['created_at'] = '2026-01-01 10:00:00';
        $row['transaction_id'] = 'import-42';
        $connection->insert($table, $row);

        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', self::POST_URL);

        $this->assertResponseIsSuccessful();
        $badge = $crawler->filter('span[title=\'{"file":"posts.csv"}\']');
        $this->assertCount(1, $badge, 'The blame context is shown once, with its data in the tooltip.');
        $this->assertSame('via import · Import batch #42', $badge->text());
    }

    public function testCompareRevisionsComposesTheChangesBetweenThem(): void
    {
        [$postId, $revisions] = $this->createPostHistory();
        $this->login();

        foreach ([[$revisions[0], $revisions[2]], [$revisions[2], $revisions[0]]] as [$from, $to]) {
            $crawler = $this->client->request('GET', \sprintf('%s/%d/compare?from=%d&to=%d', self::POST_URL, $postId, $from, $to));

            $this->assertResponseIsSuccessful();
            $this->assertSame([
                'body X → Y',
                'title A → C',
            ], $this->comparedFields($crawler), 'Revisions are compared in chronological order, whichever is picked first.');
        }
    }

    public function testCompareRevisionsStaysExactWhenOlderEntriesWerePurged(): void
    {
        [$postId, $revisions] = $this->createPostHistory();
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $connection->executeStatement('DELETE FROM '.$this->createReader()->getEntityAuditTableName(Post::class).' WHERE id = ?', [$revisions[0]]);
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d/compare?from=%d&to=%d', self::POST_URL, $postId, $revisions[1], $revisions[2]));

        $this->assertResponseIsSuccessful();
        $this->assertSame(['title A → C'], $this->comparedFields($crawler));
        $this->assertCount(0, $crawler->selectLink('Previous change'), 'There is no revision left before the purged ones.');
    }

    public function testViewerRendersDatesInTheAuditorTimezone(): void
    {
        [$postId] = $this->createPostHistory();
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = $entityManager->find(Post::class, $postId);
        $this->assertInstanceOf(Post::class, $post);
        $entityManager->remove($post); // REMOVE entries are rendered by their own branch of entry.html.twig
        $entityManager->flush();

        $revisions = $this->revisionIds(Post::class, $postId);
        $connection = $this->provider->getStorageServiceForEntity(Post::class)->getEntityManager()->getConnection();
        $table = $this->createReader()->getEntityAuditTableName(Post::class);
        foreach ($revisions as $revision) {
            $connection->executeStatement('UPDATE '.$table.' SET created_at = ? WHERE id = ?', ['2026-01-03 10:00:00', $revision]);
        }

        // Twig's default timezone (PHP's by default) differs from the auditor one (UTC in the test app):
        // 10:00 UTC is already January 4 in Pacific/Kiritimati (UTC+14).
        $twig = self::getContainer()->get(Environment::class);
        $this->assertInstanceOf(Environment::class, $twig);
        $twig->getExtension(CoreExtension::class)->setTimezone('Pacific/Kiritimati');
        $this->login();

        foreach ([
            \sprintf('%s/%d', self::POST_URL, $postId),
            \sprintf('%s/%d/revisions', self::POST_URL, $postId),
            \sprintf('%s/%d/compare?from=%d&to=%d', self::POST_URL, $postId, $revisions[0], $revisions[2]),
        ] as $url) {
            $body = $this->client->request('GET', $url)->filter('body')->text();

            $this->assertResponseIsSuccessful();
            $this->assertStringContainsString('January 3, 2026', $body, $url.' renders dates in the auditor timezone.');
            $this->assertStringNotContainsString('January 4, 2026', $body, $url.' does not convert dates to the Twig default timezone.');
        }
    }

    public function testCompareRevisionsStepsThroughHistory(): void
    {
        [$postId, $revisions] = $this->createPostHistory();
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d/compare?from=%d&to=%d', self::POST_URL, $postId, $revisions[1], $revisions[2]));

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->selectLink('Next change'));
        $crawler = $this->client->click($crawler->selectLink('Previous change')->link());

        $this->assertResponseIsSuccessful();
        $this->assertSame(['body X → Y'], $this->comparedFields($crawler));
        $this->assertCount(0, $crawler->selectLink('Previous change'));
        $crawler = $this->client->click($crawler->selectLink('Next change')->link());

        $this->assertSame(['title A → C'], $this->comparedFields($crawler));
    }

    public function testCompareRevisionsShowsRelationsAndRemoval(): void
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Title')->setBody('Body')->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
        $tag = new Tag()->setTitle('compared tag');
        $entityManager->persist($post);
        $entityManager->persist($tag);
        $entityManager->flush();

        $post->addTag($tag);
        $entityManager->flush();
        $postId = (int) $post->getId();
        $entityManager->remove($post);
        $entityManager->flush();

        $revisions = $this->revisionIds(Post::class, $postId);
        $this->login();

        $crawler = $this->client->request('GET', \sprintf('%s/%d/compare?from=%d&to=%d', self::POST_URL, $postId, $revisions[0], $revisions[\count($revisions) - 1]));

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('The object was deleted between these two revisions.', $crawler->filter('body')->text());
        $relations = $crawler->filter('[data-dh-auditor-relations] li')->each(
            static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', mb_trim($row->text()))
        );
        $this->assertCount(1, $relations);
        $this->assertMatchesRegularExpression('/^tags \+ Tag #\d+ · compared tag$/', $relations[0]);
        $this->assertStringNotContainsString('No field or relation changed', $crawler->filter('body')->text());
    }

    public function testCompareRevisionsOfAnotherObjectIsNotFound(): void
    {
        [$postId, $revisions] = $this->createPostHistory();
        [, $otherRevisions] = $this->createPostHistory();
        $this->login();

        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', \sprintf('%s/%d/compare?from=%d&to=%d', self::POST_URL, $postId, $revisions[0], $otherRevisions[1]));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    #[DataProvider('provideRevisionRoutesRejectInvalidRevisionsCases')]
    public function testRevisionRoutesRejectInvalidRevisions(string $route, array $parameters): void
    {
        $this->login();

        $this->client->catchExceptions(false);
        $this->expectException(BadRequestHttpException::class);
        $this->client->request('GET', self::POST_URL.'/1/'.$route, $parameters);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function provideRevisionRoutesRejectInvalidRevisionsCases(): iterable
    {
        yield 'compare without "to"' => ['compare', ['from' => '1']];

        yield 'compare without "from"' => ['compare', ['to' => '1']];

        yield 'compare with a non-integer' => ['compare', ['from' => '1', 'to' => 'abc']];

        yield 'compare with zero' => ['compare', ['from' => '0', 'to' => '1']];

        yield 'compare with a negative integer' => ['compare', ['from' => '-1', 'to' => '1']];

        yield 'compare with an array' => ['compare', ['from' => ['1'], 'to' => '1']];

        yield 'revisions with markup' => ['revisions', ['selected' => '<!--<script>']];
    }

    public function testCompareRevisionsOfUnauditedEntityIsNotFound(): void
    {
        $this->login();

        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Damn/1/compare?from=1&to=2');
    }

    #[DataProvider('provideRevisionRoutesCases')]
    public function testRevisionRoutesWithRoleNotGrantedForAuthorAuditViewing(string $route): void
    {
        $this->login(['ROLE_USER']);

        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author/1/'.str_replace(['{first}', '{last}'], ['1', '2'], $route));
    }

    #[DataProvider('provideRevisionRoutesCases')]
    public function testRevisionRoutesWithRoleGrantedForAuthorAuditViewing(string $route): void
    {
        $revisions = $this->revisionIds(Author::class, 1);
        $this->login(['ROLE_1']);

        $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author/1/'.str_replace(['{first}', '{last}'], [(string) $revisions[0], (string) $revisions[\count($revisions) - 1]], $route));

        $this->assertResponseIsSuccessful();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRevisionRoutesCases(): iterable
    {
        yield 'revisions' => ['revisions'];

        yield 'revisions with a selection' => ['revisions?selected={first}'];

        yield 'compare' => ['compare?from={first}&to={last}'];
    }

    public function testSelectRevisionsPicksTheFirstThenTheSecondRevision(): void
    {
        [$postId, $revisions] = $this->createPostHistory();
        $this->login();

        // the history of the object links to its revisions
        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));
        $crawler = $this->client->click($crawler->selectLink('Compare two states')->link());

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[aria-current="true"]'));
        $this->assertCount(3, $crawler->filter('ul a[href*="/revisions?selected="]'));

        $crawler = $this->client->click($crawler->filter(\sprintf('ul a[href*="selected=%d"]', $revisions[0]))->link());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[aria-current="true"]'));
        $crawler = $this->client->click($crawler->filter(\sprintf('ul a[href*="to=%d"]', $revisions[2]))->link());

        $this->assertResponseIsSuccessful();
        $this->assertSame(['body X → Y', 'title A → C'], $this->comparedFields($crawler));
    }

    public function testSelectRevisionsRejectsASelectionOfAnotherObject(): void
    {
        [$postId] = $this->createPostHistory();
        [, $otherRevisions] = $this->createPostHistory();
        $this->login();

        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', \sprintf('%s/%d/revisions?selected=%d', self::POST_URL, $postId, $otherRevisions[0]));
    }

    /**
     * Creates a post, then changes its body and its title in two separate flushes.
     *
     * @return array{int, list<int>} the post ID and its audit entry IDs, oldest first
     */
    private function createPostHistory(): array
    {
        $entityManager = $this->provider->getAuditingServiceForEntity(Post::class)->getEntityManager();
        $post = new Post()->setTitle('Title A')->setBody('Body X')->setCreatedAt(new \DateTimeImmutable('2026-01-01'));
        $entityManager->persist($post);
        $entityManager->flush();

        $post->setBody('Body Y');
        $entityManager->flush();
        $post->setTitle('Title C');
        $entityManager->flush();

        return [(int) $post->getId(), $this->revisionIds(Post::class, (int) $post->getId())];
    }

    /**
     * @return list<int> audit entry IDs of the object, oldest first
     */
    private function revisionIds(string $entity, int $id): array
    {
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $table = $this->createReader()->getEntityAuditTableName($entity);

        return array_map(intval(...), $connection->fetchFirstColumn('SELECT id FROM '.$table.' WHERE object_id = ? ORDER BY id', [(string) $id]));
    }

    /**
     * @return list<string> one "field deleted → inserted" line per compared field: the field, then the text its change
     *                      marks as deleted and as inserted
     */
    private function comparedFields(Crawler $crawler): array
    {
        return $crawler->filter('tbody tr[data-field]')->each(
            static fn (Crawler $row): string => \sprintf(
                '%s %s → %s',
                $row->attr('data-field'),
                implode('', $row->filter('del')->each(static fn (Crawler $node): string => $node->text())),
                implode('', $row->filter('ins')->each(static fn (Crawler $node): string => $node->text())),
            )
        );
    }

    /**
     * Copies an insert audit row of $entity, attributed to the given user at the given date.
     */
    private function seedUserEntry(string $entity, ?string $blameId, string $username, string $createdAt, ?string $ip = null, ?int $id = null, ?string $transactionId = null): void
    {
        $table = $this->createReader()->getEntityAuditTableName($entity);
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM '.$table." WHERE type = 'insert' ORDER BY id LIMIT 1");
        $this->assertIsArray($row);
        unset($row['id']);
        if (null !== $id) {
            $row['id'] = $id;
        }

        $row['blame_id'] = $blameId;
        $row['blame'] = json_encode(['username' => $username] + (null === $ip ? [] : ['ip' => $ip]), JSON_THROW_ON_ERROR);
        $row['created_at'] = $createdAt;
        $row['transaction_id'] = $transactionId ?? 'seeded-'.bin2hex(random_bytes(8)); // an action of its own by default
        $connection->insert($table, $row);
    }

    /**
     * Copies an insert audit row of $entity once per object id: one action that creates these objects.
     *
     * @param list<int> $objectIds
     */
    private function seedAction(string $entity, string $transactionId, string $createdAt, array $objectIds, ?string $blameId = null): void
    {
        $table = $this->createReader()->getEntityAuditTableName($entity);
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM '.$table." WHERE type = 'insert' ORDER BY id LIMIT 1");
        $this->assertIsArray($row);
        unset($row['id']);
        foreach ($objectIds as $objectId) {
            $diffs = json_decode((string) $row['diffs'], true, 512, JSON_THROW_ON_ERROR);
            \assert(\is_array($diffs) && \is_array($diffs['source'] ?? null));
            $diffs['source']['id'] = $objectId;
            $diffs['source']['label'] = 'object '.$objectId;
            $connection->insert($table, [
                'object_id' => (string) $objectId,
                'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
                'blame_id' => $blameId,
                'created_at' => $createdAt,
                'transaction_id' => $transactionId,
            ] + $row);
        }
    }

    /**
     * @return list<string> key of each rendered action, in display order
     */
    private function actionKeys(Crawler $crawler): array
    {
        return $crawler->filter('[data-dh-auditor-action]')->each(static fn (Crawler $action): string => (string) $action->attr('data-dh-auditor-action'));
    }

    /**
     * @return list<string> FQCN of the entity of each rendered action, in display order
     */
    private function timelineEntities(Crawler $crawler): array
    {
        return $crawler->filter('[data-dh-auditor-entities]')->each(static fn (Crawler $action): string => explode(' ', (string) $action->attr('data-dh-auditor-entities'))[0]);
    }

    private function seedPeriodEntries(): void
    {
        $reader = $this->createReader();
        $connection = $this->provider->getStorageServiceForEntity(Author::class)->getEntityManager()->getConnection();
        $table = $reader->getEntityAuditTableName(Author::class);
        $row = $connection->fetchAssociative('SELECT * FROM '.$table.' LIMIT 1');
        $this->assertIsArray($row);
        unset($row['id']);
        $connection->executeStatement('DELETE FROM '.$table);
        $row['object_id'] = '101';
        $row['blame_id'] = 'period-user';
        $row['type'] = 'insert';

        foreach (['2026-02-09 23:59:59', '2026-02-10 00:00:00', '2026-02-10 23:59:59', '2026-02-11 00:00:00'] as $index => $date) {
            $row['created_at'] = $date;
            $row['transaction_id'] = 'period-'.$index; // an action of its own
            $connection->insert($table, $row);
        }
    }

    /**
     * @return list<string> the audited entities FQCN, in the order their cards are rendered
     */
    private function renderedEntities(Crawler $crawler): array
    {
        return $crawler->filter('[data-dh-auditor-entity]')->each(static fn (Crawler $node): string => (string) $node->attr('data-dh-auditor-entity'));
    }

    private function login(array $roles = []): void
    {
        [$username, $configuredRoles] = \in_array('ROLE_1', $roles, true)
            ? ['role1.user', ['ROLE_1']]
            : ['dark.vador', ['ROLE_USER']];

        $user = new InMemoryUser(
            $username,
            '$argon2id$v=19$m=65536,t=4,p=1$g1yZVCS0GJ32k2fFqBBtqw$359jLODXkhqVWtD/rf+CjiNz9r/kIvhJlenPBnW851Y',
            $configuredRoles
        );

        $this->client->loginUser($user);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }
}
