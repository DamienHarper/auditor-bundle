<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\User;

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

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(1, $breadcrumbs, 'Nav has 1 item.');
        $this->assertSame('Home', trim($breadcrumbs->text()), 'Nav has 1 item: Home');

        $cards = $crawler->filter('div.auditor-audits > div');
        $this->assertCount(4, $cards, 'There are 4 entity audits.');

        $expected = [
            [Author::class, 'author', '7 operation(s)', 'View audit'],
            [Comment::class, 'comment', '3 operation(s)', 'View audit'],
            [Post::class, 'post', '21 operation(s)', 'View audit'],
            [Tag::class, 'tag', '15 operation(s)', 'View audit'],
        ];
        $cards->each(static function ($row, $rowIndex) use ($expected): void {
            $cell = $row->filter('div > h3 > code');
            self::assertSame($expected[$rowIndex][0], trim((string) $cell->text()), 'Entity is OK');

            $cell = $row->filter('div > p');
            self::assertSame($expected[$rowIndex][1], trim((string) $cell->text()), 'Tablename is OK');

            $cell = $row->filter('div > dl > dt');
            self::assertSame($expected[$rowIndex][2], trim((string) $cell->text()), 'Operation count is OK');

            $cell = $row->filter('div > dl > dd > a');
            self::assertSame($expected[$rowIndex][3], trim((string) $cell->text()), 'Link is OK');
        });
    }

    #[Depends('testListAuditsAnonymously')]
    public function testListAuditsWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(1, $breadcrumbs, 'Nav has 1 item.');
        $this->assertSame('Home', trim($breadcrumbs->text()), 'Nav has 1 item: Home');

        $cards = $crawler->filter('div.auditor-audits > div');
        $this->assertCount(3, $cards, 'There are 3 entity audits.');

        $expected = [
            [Comment::class, 'comment', '3 operation(s)', 'View audit'],
            [Post::class, 'post', '21 operation(s)', 'View audit'],
            [Tag::class, 'tag', '15 operation(s)', 'View audit'],
        ];
        $cards->each(static function ($row, $rowIndex) use ($expected): void {
            $cell = $row->filter('div > h3 > code');
            self::assertSame($expected[$rowIndex][0], trim((string) $cell->text()), 'Entity is OK');

            $cell = $row->filter('div > p');
            self::assertSame($expected[$rowIndex][1], trim((string) $cell->text()), 'Tablename is OK');

            $cell = $row->filter('div > dl > dt');
            self::assertSame($expected[$rowIndex][2], trim((string) $cell->text()), 'Operation count is OK');

            $cell = $row->filter('div > dl > dd > a');
            self::assertSame($expected[$rowIndex][3], trim((string) $cell->text()), 'Link is OK');
        });
    }

    #[Depends('testListAuditsWithRoleNotGrantedForAuthorAuditViewing')]
    public function testListAuditsWithRoleGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE1']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(1, $breadcrumbs, 'Nav has 1 item.');
        $this->assertSame('Home', trim($breadcrumbs->text()), 'Nav has 1 item: Home');

        $cards = $crawler->filter('div.auditor-audits > div');
        $this->assertCount(4, $cards, 'There are 4 entity audits.');

        $expected = [
            [Author::class, 'author', '7 operation(s)', 'View audit'],
            [Comment::class, 'comment', '3 operation(s)', 'View audit'],
            [Post::class, 'post', '21 operation(s)', 'View audit'],
            [Tag::class, 'tag', '15 operation(s)', 'View audit'],
        ];
        $cards->each(static function ($row, $rowIndex) use ($expected): void {
            $cell = $row->filter('div > h3 > code');
            self::assertSame($expected[$rowIndex][0], trim((string) $cell->text()), 'Entity is OK');

            $cell = $row->filter('div > p');
            self::assertSame($expected[$rowIndex][1], trim((string) $cell->text()), 'Tablename is OK');

            $cell = $row->filter('div > dl > dt');
            self::assertSame($expected[$rowIndex][2], trim((string) $cell->text()), 'Operation count is OK');

            $cell = $row->filter('div > dl > dd > a');
            self::assertSame($expected[$rowIndex][3], trim((string) $cell->text()), 'Link is OK');
        });
    }

    #[Depends('testListAuditsAnonymously')]
    public function testShowEntityHistoryAnonymously(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(2, $breadcrumbs, 'Nav has 2 item.');
        $this->assertSame('Home', trim($breadcrumbs->eq(0)->text()), 'Nav has 1 item: Home');
        $this->assertSame(Author::class, trim($breadcrumbs->eq(1)->children('div > a > code')->text()), 'Nav has 1 item: '.Author::class);
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
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(2, $breadcrumbs, 'Nav has 2 item.');
        $this->assertSame('Home', trim($breadcrumbs->eq(0)->text()), 'Nav has 1 item: Home');
        $this->assertSame(Author::class, trim($breadcrumbs->eq(1)->children('div > a > code')->text()), 'Nav has 1 item: '.Author::class);
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
        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->getTransactionHash());

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(2, $breadcrumbs, 'Nav has 2 item.');
        $this->assertSame('Home', trim($breadcrumbs->eq(0)->text()), 'Nav has 1 item: Home');
        $this->assertSame($first->getTransactionHash(), trim($breadcrumbs->eq(1)->children('div > a')->text()), 'Nav has 1 item: '.$first->getTransactionHash());

        $sections = $crawler->filter('.flow-root > div');
        $this->assertCount(4, $sections, 'There are 3 sections.');
        $this->assertSame(Author::class, trim($sections->eq(0)->children('div > code')->text()), Author::class);
        $this->assertSame(Post::class, trim($sections->eq(1)->children('div > code')->text()), Post::class);
        $this->assertSame(Comment::class, trim($sections->eq(2)->children('div > code')->text()), Comment::class);
    }

    #[Depends('testShowTransactionHistoryAnonymously')]
    public function testShowTransactionHistoryWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $reader = $this->createReader();

        /** @var Entry[] $audits */
        $audits = $reader->createQuery(Author::class)->execute();
        $first = $audits[\count($audits) - 1];

        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->getTransactionHash());

        // asserts that the response status code is 2xx
        $this->assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        $this->assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('nav > ol > li');
        $this->assertCount(2, $breadcrumbs, 'Nav has 2 item.');
        $this->assertSame('Home', trim($breadcrumbs->eq(0)->text()), 'Nav has 1 item: Home');
        $this->assertSame($first->getTransactionHash(), trim($breadcrumbs->eq(1)->children('div > a')->text()), 'Nav has 1 item: '.$first->getTransactionHash());

        $sections = $crawler->filter('.flow-root > div');
        $this->assertCount(3, $sections, 'There are 2 sections.');
        $this->assertSame(Post::class, trim($sections->eq(0)->children('div > code')->text()), Post::class);
        $this->assertSame(Comment::class, trim($sections->eq(1)->children('div > code')->text()), Comment::class);
    }

    private function login(array $roles = []): void
    {
        $class = class_exists(User::class) ? User::class : InMemoryUser::class;
        $user = new $class(
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
