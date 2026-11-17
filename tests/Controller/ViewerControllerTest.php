<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Enum\EntitySort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

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

    /**
     * @return list<string> the audited entities FQCN, in the order their cards are rendered
     */
    private function renderedEntities(Crawler $crawler): array
    {
        return $crawler->filter('a[href*="/audit/"] span[title]')->each(static fn (Crawler $node): string => (string) $node->attr('title'));
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
