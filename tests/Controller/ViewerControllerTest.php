<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
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

        // provider with 1 em for both storage and auditing
        $this->createAndInitDoctrineProvider();

        // declare audited entites
        $this->configureEntities();

        // setup entity and audit schemas
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();

        // setup (seed) entities
        $this->setupEntities();
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
        $this->assertGreaterThanOrEqual(4, $cards->count(), 'There are at least 4 entity audit cards.');
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
        $this->login(['ROLE1']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check cards
        $cards = $crawler->filter('a[href*="/audit/"]');
        $this->assertGreaterThanOrEqual(4, $cards->count(), 'There are at least 4 entity audit cards.');
    }

    #[Depends('testListAuditsAnonymously')]
    public function testShowEntityHistoryAnonymously(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check back link
        $backLink = $crawler->filter('a[href="/audit"]');
        $this->assertGreaterThanOrEqual(1, $backLink->count(), 'Back link to entities exists');

        // Check page title contains entity name
        $title = $crawler->filter('h1');
        $this->assertStringContainsString('Author', $title->text(), 'Page title contains Author');

        // Check entries are displayed
        $entries = $crawler->filter('details');
        $this->assertGreaterThanOrEqual(1, $entries->count(), 'There are audit entries');
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
        $this->login(['ROLE1']);
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
        $this->login(['ROLE1']);
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
        $this->login(['ROLE1']);
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
        $this->login(['ROLE1']);
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

    private function login(array $roles = []): void
    {
        $user = new InMemoryUser(
            'dark.vador',
            '$argon2id$v=19$m=65536,t=4,p=1$g1yZVCS0GJ32k2fFqBBtqw$359jLODXkhqVWtD/rf+CjiNz9r/kIvhJlenPBnW851Y',
            $roles
        );

        $this->client->loginUser($user);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }
}
