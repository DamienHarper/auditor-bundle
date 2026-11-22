<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\Auditing\Snapshot\Snapshotter;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
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

    public function testShowEntityHistoryFlagsSnapshots(): void
    {
        new Snapshotter($this->provider)->snapshot(Tag::class, force: true);

        $this->login();
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Tag');

        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');
        $this->assertGreaterThanOrEqual(1, $crawler->filter('summary:contains("SNAPSHOT")')->count(), 'Snapshot entries carry the SNAPSHOT badge.');
        $this->assertStringContainsString('existing record captured', $crawler->text(), 'Snapshot entries are described as such.');
        $this->assertGreaterThanOrEqual(1, $crawler->filter('summary:contains("INSERT")')->count(), 'Regular insert entries keep the INSERT badge.');
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
        $this->assertStringContainsString('3 events', $crawler->filter('h1+p')->text(), 'Exactly 3 coauthor changes');
        $this->assertSame('coauthor', $crawler->filter('#filter-field option[selected]')->attr('value'), 'The filtered field is selected');

        // Collections match the associations and dissociations recorded on them.
        $crawler = $this->client->request('GET', $uri.'?field=tags');
        $this->assertStringContainsString('10 events', $crawler->filter('h1+p')->text(), '8 tag associations and 2 dissociations');

        // Filters are combined.
        $crawler = $this->client->request('GET', $uri.'?field=tags&type=dissociate');
        $this->assertStringContainsString('2 events', $crawler->filter('h1+p')->text(), '2 tag dissociations');

        // Clearing the period keeps the changed field filter.
        $crawler = $this->client->request('GET', $uri.'?field=tags&date_from=2000-01-01');
        $this->assertStringContainsString('10 events', $crawler->filter('h1+p')->text(), 'Combined with a period');
        $this->assertStringContainsString('field=tags', (string) $crawler->selectLink('Clear period')->attr('href'), 'Clearing the period keeps the field');

        // Keys of the association diffs are not fields.
        $crawler = $this->client->request('GET', $uri.'?field=source');
        $this->assertStringContainsString('0 events', $crawler->filter('h1+p')->text(), 'source is not a field');

        // A field that is no longer mapped stays filterable and selectable.
        $crawler = $this->client->request('GET', $uri.'?field=subtitle');
        $this->assertStringContainsString('0 events', $crawler->filter('h1+p')->text(), 'Unknown field matches nothing');
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
                'body Body X → Body Y',
                'title Title A → Title C',
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
        $this->assertSame(['title Title A → Title C'], $this->comparedFields($crawler));
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
        $this->assertSame(['body Body X → Body Y'], $this->comparedFields($crawler));
        $this->assertCount(0, $crawler->selectLink('Previous change'));
        $crawler = $this->client->click($crawler->selectLink('Next change')->link());

        $this->assertSame(['title Title A → Title C'], $this->comparedFields($crawler));
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
        $relations = $crawler->filter('caption + tbody tr')->each(
            static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', mb_trim($row->text()))
        );
        $this->assertCount(1, $relations);
        $this->assertMatchesRegularExpression('/^tags LINK Tag #\d+ · compared tag$/', $relations[0]);
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

        $crawler = $this->client->request('GET', \sprintf('%s/%d', self::POST_URL, $postId));
        $crawler = $this->client->click($crawler->filter('a[aria-label="Compare revisions"]')->last()->link());

        // The revision whose icon was clicked is already selected
        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[aria-current="true"]'));
        $this->assertCount(2, $crawler->filter('ul a[href*="/compare?"]'));

        $crawler = $this->client->request('GET', \sprintf('%s/%d/revisions', self::POST_URL, $postId));

        $this->assertResponseIsSuccessful();
        $this->assertCount(0, $crawler->filter('[aria-current="true"]'));
        $this->assertCount(3, $crawler->filter('ul a[href*="/revisions?selected="]'));

        $crawler = $this->client->click($crawler->filter(\sprintf('ul a[href*="selected=%d"]', $revisions[0]))->link());

        $this->assertResponseIsSuccessful();
        $this->assertCount(1, $crawler->filter('[aria-current="true"]'));
        $crawler = $this->client->click($crawler->filter(\sprintf('ul a[href*="to=%d"]', $revisions[2]))->link());

        $this->assertResponseIsSuccessful();
        $this->assertSame(['body Body X → Body Y', 'title Title A → Title C'], $this->comparedFields($crawler));
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
     * @return list<string> one "field old → new" line per compared field
     */
    private function comparedFields(Crawler $crawler): array
    {
        return $crawler->filter('thead + tbody tr')->each(
            static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', mb_trim($row->text()))
        );
    }

    /**
     * Copies an insert audit row of $entity, attributed to the given user at the given date.
     */
    private function seedUserEntry(string $entity, ?string $blameId, string $username, string $createdAt, ?string $ip = null): void
    {
        $table = $this->createReader()->getEntityAuditTableName($entity);
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();
        $row = $connection->fetchAssociative('SELECT * FROM '.$table." WHERE type = 'insert' ORDER BY id LIMIT 1");
        $this->assertIsArray($row);
        unset($row['id']);
        $row['blame_id'] = $blameId;
        $row['blame'] = json_encode(['username' => $username] + (null === $ip ? [] : ['ip' => $ip]), JSON_THROW_ON_ERROR);
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
