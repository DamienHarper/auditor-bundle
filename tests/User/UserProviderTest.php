<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\User;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\DHAuditorBundle;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\HttpKernelBrowser;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @internal
 *
 * @small
 *
 * @coversNothing
 */
final class UserProviderTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private DoctrineProvider $provider;
    private HttpKernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();

        // provider with 1 em for both storage and auditing
        $this->createAndInitDoctrineProvider();

        // declare audited entites
        $this->configureEntities();

        // setup entity and audit schemas
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
    }

    public function testBlameUser(): void
    {
        $auditingServices = [
            Post::class => $this->provider->getAuditingServiceForEntity(Post::class),
        ];

        $user = $this->createUser('dark.vador');

        $firewallName = 'main';

        if (Kernel::MAJOR_VERSION >= 6) {
            $token = new UsernamePasswordToken($user, $firewallName, $user->getRoles());
        } else {
            $token = new UsernamePasswordToken($user, null, $firewallName, $user->getRoles());
        }

        self::getContainer()->get('security.token_storage')->setToken($token);

        $post = new Post();
        $post
            ->setTitle('Blameable post')
            ->setBody('yet another post')
            ->setCreatedAt(new \DateTimeImmutable('2020-01-17 22:17:34'))
        ;
        $auditingServices[Post::class]->getEntityManager()->persist($post);
        $this->flushAll($auditingServices);

        // get history
        $entries = $this->createReader()->createQuery(Post::class)->execute();
        self::assertSame('dark.vador', $entries[0]->getUsername());
    }

    public function testBlameImpersonator(): void
    {
        $auditingServices = [
            Post::class => $this->provider->getAuditingServiceForEntity(Post::class),
        ];

        $user = $this->createUser('dark.vador');
        $secondUser = $this->createUser('second_user');

        $firewallName = 'main';

        if (Kernel::MAJOR_VERSION >= 6) {
            $userToken = new UsernamePasswordToken($user, $firewallName, $user->getRoles());
            $token = new SwitchUserToken($secondUser, $firewallName, $secondUser->getRoles(), $userToken);
        } else {
            $userToken = new UsernamePasswordToken($user, null, $firewallName, $user->getRoles());
            $token = new SwitchUserToken($secondUser, null, $firewallName, $secondUser->getRoles(), $userToken);
        }

        self::getContainer()->get('security.token_storage')->setToken($token);

        $post = new Post();
        $post
            ->setTitle('Blameable post')
            ->setBody('yet another post')
            ->setCreatedAt(new \DateTimeImmutable('2020-01-17 22:17:34'))
        ;
        $auditingServices[Post::class]->getEntityManager()->persist($post);
        $this->flushAll($auditingServices);

        // get history
        $entries = $this->createReader()->createQuery(Post::class)->execute();
        self::assertSame('second_user[impersonator dark.vador]', $entries[0]->getUsername());
    }

    protected function getBundleClass()
    {
        return DHAuditorBundle::class;
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }

    private function createUser(string $username): UserInterface
    {
        $class = class_exists(User::class) ? User::class : InMemoryUser::class;

        return new $class(
            $username,
            '$argon2id$v=19$m=65536,t=4,p=1$g1yZVCS0GJ32k2fFqBBtqw$359jLODXkhqVWtD/rf+CjiNz9r/kIvhJlenPBnW851Y',
            []
        );
    }
}
