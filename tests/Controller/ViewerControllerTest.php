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
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
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

    #[Depends('testListAuditsAnonymously')]
    public function testShowUserGlobalHistory(): void
    {
        $userId = '424242';
        $username = 'global.history.tester';

        $this->stampBlame(Post::class, $userId, $username, 'MIN');
        $this->stampBlame(Comment::class, $userId, $username, 'MAX');

        $this->login();
        $crawler = $this->client->request('GET', '/audit/user/'.$userId);

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title contains auditor');

        // Check page title contains the username
        $title = $crawler->filter('h1');
        $this->assertStringContainsString($username, $title->text(), 'Page title contains the username');

        // Check events count across both entities
        $subtitle = $crawler->filter('h1+p');
        $this->assertStringContainsString('2 events', $subtitle->text(), 'Subtitle contains 2 events');

        // Check entries from both entities are shown in a single timeline
        $postLinks = $crawler->filter('a[href*="Post"]');
        $commentLinks = $crawler->filter('a[href*="Comment"]');
        $this->assertGreaterThanOrEqual(1, $postLinks->count(), 'A Post entry is shown');
        $this->assertGreaterThanOrEqual(1, $commentLinks->count(), 'A Comment entry is shown');
    }

    #[Depends('testShowUserGlobalHistory')]
    public function testShowUserGlobalHistoryWithoutMatches(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit/user/does-not-exist');

        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');
        $this->assertStringContainsString('No audit entries found', $crawler->filter('body')->text());
    }

    /**
     * Stamps the first or last audit row of $entity's audit table with a given blame_id/username,
     * simulating an action performed by a specific user (test fixtures don't log in before seeding).
     */
    private function stampBlame(string $entity, string $userId, string $username, string $aggregate): void
    {
        $reader = $this->createReader();
        $table = $reader->getEntityAuditTableName($entity);
        $connection = $this->provider->getStorageServiceForEntity($entity)->getEntityManager()->getConnection();

        $connection->executeStatement(
            \sprintf('UPDATE %s SET blame_id = :id, blame = :blame WHERE id = (SELECT %s(id) FROM %s)', $table, $aggregate, $table),
            ['id' => $userId, 'blame' => json_encode(['username' => $username], \JSON_THROW_ON_ERROR)]
        );
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
