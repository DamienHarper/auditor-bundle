<?php

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Security\SecurityProvider;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\User;

/**
 * @internal
 */
final class ViewerControllerTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    /**
     * @var AbstractBrowser
     */
    private $client;

    /**
     * @see https://symfony.com/doc/current/testing.html
     * @see https://github.com/symfony/panther
     */
    public function testListAuditsAnonymously(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit');

        // asserts a specific 200 status code
//        self::assertEquals(200, $this->client->getResponse()->getStatusCode(), 'Response status is 200');
//        self::assertEquals(Response::HTTP_OK, $this->client->getResponse()->getStatusCode(), 'Response status is 200');

        // asserts that the response status code is 404
//        self::assertTrue($this->client->getResponse()->isNotFound(), 'Response status is 404');

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(1, $breadcrumbs->count(), 'Nav has 1 item.');
        self::assertSame('Home', $breadcrumbs->text(), 'Nav has 1 item: Home');

        $rows = $crawler->filter('table.table-hover > tbody > tr');
        self::assertSame(4, $rows->count(), 'There are 4 entity audits.');

        $expected = [
            [Author::class, 'author', '5 operations', 'View audit'],
            [Comment::class, 'comment', '3 operations', 'View audit'],
            [Post::class, 'post', '15 operations', 'View audit'],
            [Tag::class, 'tag', '15 operations', 'View audit'],
        ];
        $rows->each(function ($row, $rowIndex) use ($expected): void {
            $cells = $row->filter('td');
            self::assertSame(4, $cells->count(), 'Each row is composed of 4 cells.');
            $cells->each(function ($cell, $cellIndex) use ($expected, $rowIndex): void {
                self::assertSame($expected[$rowIndex][$cellIndex], $cell->text(), sprintf('Cell #%s of row #%s is ok.', $cellIndex, $rowIndex));
            });
        });
    }

    /**
     * @depends testListAuditsAnonymously
     */
    public function testListAuditsWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(1, $breadcrumbs->count(), 'Nav has 1 item.');
        self::assertSame('Home', $breadcrumbs->text(), 'Nav has 1 item: Home');

        $rows = $crawler->filter('table.table-hover > tbody > tr');
        self::assertSame(3, $rows->count(), 'There are 3 entity audits.');

        $expected = [
            [Comment::class, 'comment', '3 operations', 'View audit'],
            [Post::class, 'post', '15 operations', 'View audit'],
            [Tag::class, 'tag', '15 operations', 'View audit'],
        ];
        $rows->each(function ($row, $rowIndex) use ($expected): void {
            $cells = $row->filter('td');
            self::assertSame(4, $cells->count(), 'Each row is composed of 4 cells.');
            $cells->each(function ($cell, $cellIndex) use ($expected, $rowIndex): void {
                self::assertSame($expected[$rowIndex][$cellIndex], $cell->text(), sprintf('Cell #%s of row #%s is ok.', $cellIndex, $rowIndex));
            });
        });
    }

    /**
     * @depends testListAuditsWithRoleNotGrantedForAuthorAuditViewing
     */
    public function testListAuditsWithRoleGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE1']);
        $crawler = $this->client->request('GET', '/audit');

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(1, $breadcrumbs->count(), 'Nav has 1 item.');
        self::assertSame('Home', $breadcrumbs->text(), 'Nav has 1 item: Home');

        $rows = $crawler->filter('table.table-hover > tbody > tr');
        self::assertSame(4, $rows->count(), 'There are 4 entity audits.');

        $expected = [
            [Author::class, 'author', '5 operations', 'View audit'],
            [Comment::class, 'comment', '3 operations', 'View audit'],
            [Post::class, 'post', '15 operations', 'View audit'],
            [Tag::class, 'tag', '15 operations', 'View audit'],
        ];
        $rows->each(function ($row, $rowIndex) use ($expected): void {
            $cells = $row->filter('td');
            self::assertSame(4, $cells->count(), 'Each row is composed of 4 cells.');
            $cells->each(function ($cell, $cellIndex) use ($expected, $rowIndex): void {
                self::assertSame($expected[$rowIndex][$cellIndex], $cell->text(), sprintf('Cell #%s of row #%s is ok.', $cellIndex, $rowIndex));
            });
        });
    }

    /**
     * @depends testListAuditsAnonymously
     */
    public function testShowEntityHistoryAnonymously(): void
    {
        $this->login();
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(2, $breadcrumbs->count(), 'Nav has 2 items.');
        self::assertSame('Home', $breadcrumbs->eq(0)->text(), 'Nav has 1 item: Home');
        self::assertSame(Author::class, $breadcrumbs->eq(1)->text(), 'Nav has 1 item: '.Author::class);
    }

    /**
     * @depends testShowEntityHistoryAnonymously
     */
    public function testShowEntityHistoryWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE_USER']);
        $this->client->catchExceptions(false);
        $this->expectException(AccessDeniedException::class);
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        self::assertSame(403, $this->client->getResponse()->getStatusCode(), 'Response status is 403');
    }

    /**
     * @depends testShowEntityHistoryWithRoleNotGrantedForAuthorAuditViewing
     */
    public function testShowEntityHistoryWithRoleGrantedForAuthorAuditViewing(): void
    {
        $this->login(['ROLE1']);
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Author');

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(2, $breadcrumbs->count(), 'Nav has 2 items.');
        self::assertSame('Home', $breadcrumbs->eq(0)->text(), 'Nav has 1 item: Home');
        self::assertSame(Author::class, $breadcrumbs->eq(1)->text(), 'Nav has 1 item: '.Author::class);
    }

    /**
     * @depends testShowEntityHistoryWithRoleGrantedForAuthorAuditViewing
     */
    public function testShowEntityHistoryOfUnauditedEntity(): void
    {
        $this->login();

        $this->client->catchExceptions(false);
        $this->expectException(NotFoundHttpException::class);
        $crawler = $this->client->request('GET', '/audit/DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Damn');

        self::assertSame(404, $this->client->getResponse()->getStatusCode(), 'Response status is 404');
    }

    /**
     * @depends testShowEntityHistoryWithRoleNotGrantedForAuthorAuditViewing
     */
    public function testShowTransactionHistoryAnonymously(): void
    {
        $reader = $this->createReader();

        /** @var Entry[] $audits */
        $audits = $reader->createQuery(Author::class)->execute();
        $first = $audits[\count($audits) - 1];

        $this->login();
        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->getTransactionHash());

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(2, $breadcrumbs->count(), 'Nav has 2 items.');
        self::assertSame('Home', $breadcrumbs->eq(0)->text(), 'Nav has 1 item: Home');
        $expected = 'transaction #'.$first->getTransactionHash();
        self::assertSame($expected, $breadcrumbs->eq(1)->text(), 'Nav has 1 item: '.$expected);

        $sections = $crawler->filter('h4.card-title');
        self::assertSame(4, $sections->count(), 'There are 4 sections.');
        $expected = 'Transaction #'.$first->getTransactionHash().' (entity per entity)';
        self::assertSame($expected, $sections->eq(0)->text(), $expected);
        $expected = Author::class.' (most recent first)';
        self::assertSame($expected, $sections->eq(1)->text(), $expected);
        $expected = Post::class.' (most recent first)';
        self::assertSame($expected, $sections->eq(2)->text(), $expected);
        $expected = Comment::class.' (most recent first)';
        self::assertSame($expected, $sections->eq(3)->text(), $expected);
    }

    /**
     * @depends testShowTransactionHistoryAnonymously
     */
    public function testShowTransactionHistoryWithRoleNotGrantedForAuthorAuditViewing(): void
    {
        $reader = $this->createReader();

        /** @var Entry[] $audits */
        $audits = $reader->createQuery(Author::class)->execute();
        $first = $audits[\count($audits) - 1];

        $this->login(['ROLE_USER']);
        $crawler = $this->client->request('GET', '/audit/transaction/'.$first->getTransactionHash());

        // asserts that the response status code is 2xx
        self::assertTrue($this->client->getResponse()->isSuccessful(), 'Response status is 2xx');

        self::assertPageTitleContains('auditor', 'Title is auditor');

        $breadcrumbs = $crawler->filter('ol.breadcrumb > li');
        self::assertSame(2, $breadcrumbs->count(), 'Nav has 2 items.');
        self::assertSame('Home', $breadcrumbs->eq(0)->text(), 'Nav has 1 item: Home');
        $expected = 'transaction #'.$first->getTransactionHash();
        self::assertSame($expected, $breadcrumbs->eq(1)->text(), 'Nav has 1 item: '.$expected);

        $sections = $crawler->filter('h4.card-title');
        self::assertSame(3, $sections->count(), 'There are 3 sections.');
        $expected = 'Transaction #'.$first->getTransactionHash().' (entity per entity)';
        self::assertSame($expected, $sections->eq(0)->text(), $expected);
        $expected = Post::class.' (most recent first)';
        self::assertSame($expected, $sections->eq(1)->text(), $expected);
        $expected = Comment::class.' (most recent first)';
        self::assertSame($expected, $sections->eq(2)->text(), $expected);
    }

    private function login(array $roles = []): void
    {
        $session = self::$container->get('session');
        $user = new User(
            'dark.vador',
            '$argon2id$v=19$m=65536,t=4,p=1$g1yZVCS0GJ32k2fFqBBtqw$359jLODXkhqVWtD/rf+CjiNz9r/kIvhJlenPBnW851Y',
            $roles
        );

        $firewallName = 'main';

        $token = new UsernamePasswordToken($user, null, $firewallName, $user->getRoles());
        $session->set('_security_'.$firewallName, serialize($token));
        $session->save();

        self::$container->get('security.token_storage')->setToken($token);

        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
    }

    private function fixRequestStack(): void
    {
        $this->client->request('GET', '/audit');
        $requestStack = $this->getMockBuilder(RequestStack::class)->getMock();
        $requestStack
            ->method('getCurrentRequest')
            ->willReturn($this->client->getRequest())
        ;

        $reflectedClass = new ReflectionClass(SecurityProvider::class);
        $reflectedProperty = $reflectedClass->getProperty('requestStack');
        $reflectedProperty->setAccessible(true);

        $reflectedProperty->setValue(self::$container->get(SecurityProvider::class), $requestStack);
    }

    private function createAndInitDoctrineProvider(): void
    {
        if (!self::$booted) {
            $this->client = self::createClient(); // boots the Kernel and populates container
        }
        $this->login();
        $this->fixRequestStack();
        $this->provider = self::$container->get(DoctrineProvider::class);
    }
}
