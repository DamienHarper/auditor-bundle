<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Controller\ViewerController;
use DH\AuditorBundle\Enum\EntitySort;
use DH\AuditorBundle\Viewer\ActivityGraphProvider;
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

/**
 * @internal
 */
#[Small]
final class ViewerControllerTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

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
                'count' => $query->count(),
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

        // Check filtered events not found
        $crawler = $this->client->request('GET', $uri.'?type=associate&user=1111111');
        $subtitle = $crawler->filter('h1+p');
        $this->assertStringContainsString('0 events', $subtitle->text(), 'Subtitle contains 0 events');
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

        // Check transaction ID is displayed
        $hash = $crawler->filter('span.font-mono');
        $this->assertStringContainsString($first->transactionId, $hash->text(), 'Transaction ID is displayed');

        // Check entries grouped by entity
        $entitySections = $crawler->filter('h2');
        $this->assertGreaterThanOrEqual(1, $entitySections->count(), 'There are entity sections');
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
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author/101', $filters);

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($expectedCount.' events', $crawler->filter('h1+p')->text());
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
        $uri = '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author/101';
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
        $this->assertStringContainsString($expectedCount.' events', $filtered->filter('h1+p')->text());
        $this->assertSame($today->format('Y-m-d'), $filtered->filter('[name="date_from"]')->attr('value'));
        $this->assertSame($today->format('Y-m-d'), $filtered->filter('[name="date_to"]')->attr('value'));

        $emptyDay = $this->client->click($bars->first()->link());
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('0 events', $emptyDay->filter('h1+p')->text());
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
        $this->assertStringContainsString('3 events', $crawler->filter('h1+p')->text());
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
        $this->assertStringContainsString(\count($expectedEntities).' event', $crawler->filter('h1+p')->text(), 'Denied entities are not counted');
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
        $this->assertStringContainsString('4 events', $crawler->filter('h1+p')->text());
        $this->assertSame($hasNextPage, $crawler->filter('a[href="/audit/user/timeline-user?page=2"]')->count() > 0);
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

    public function testShowUserTimelineWithoutMatches(): void
    {
        $this->login(['ROLE_1']);
        $crawler = $this->client->request('GET', '/audit/user/does-not-exist');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('does-not-exist', $crawler->filter('h1')->text());
        $this->assertStringContainsString('No audit entries found', $crawler->filter('body')->text());
    }

    public function testShowUserTimelineRejectsTooLongIdentifier(): void
    {
        $this->login(['ROLE_1']);
        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $this->client->request('GET', '/audit/user/'.str_repeat('a', 256));
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

    /**
     * Copies an insert audit row of $entity, attributed to the given user at the given date.
     */
    private function seedUserEntry(string $entity, ?string $blameId, string $username, string $createdAt): void
    {
        $table = $this->createReader()->getEntityAuditTableName($entity);
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM '.$table." WHERE type = 'insert' ORDER BY id LIMIT 1");
        $this->assertIsArray($row);
        unset($row['id']);
        $row['blame_id'] = $blameId;
        $row['blame'] = json_encode(['username' => $username], JSON_THROW_ON_ERROR);
        $row['created_at'] = $createdAt;
        $connection->insert($table, $row);
    }

    /**
     * @return list<string> FQCN of the entity of each rendered entry, in display order
     */
    private function timelineEntities(Crawler $crawler): array
    {
        $titles = $crawler->filter('a[title]')->each(static fn (Crawler $link): string => (string) $link->attr('title'));

        return array_values(array_filter($titles, static fn (string $title): bool => str_starts_with($title, 'DH\\')));
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

        foreach (['2026-02-09 23:59:59', '2026-02-10 00:00:00', '2026-02-10 23:59:59', '2026-02-11 00:00:00'] as $date) {
            $row['created_at'] = $date;
            $connection->insert($table, $row);
        }
    }

    /**
     * @return list<string> the audited entities FQCN, in the order their cards are rendered
     */
    private function renderedEntities(Crawler $crawler): array
    {
        return $crawler->filter('a[href*="/audit/"] + div span[title]')->each(static fn (Crawler $node): string => (string) $node->attr('title'));
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
