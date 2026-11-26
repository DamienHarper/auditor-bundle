<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\User;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\Auditor\User\User;
use DH\AuditorBundle\User\UserProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @internal
 */
#[Small]
final class UserProviderTest extends WebTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private DoctrineProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        self::createClient();

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
        $token = new UsernamePasswordToken($user, $firewallName, $user->getRoles());

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
        $this->assertSame('dark.vador', $entries[0]->username);
        $this->assertSame(InMemoryUser::class, $entries[0]->userFqdn, 'user_fqdn is the class of the user of the token.');
        $this->assertNull($entries[0]->impersonator);
    }

    public function testTheUserOfTheToken(): void
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($this->createUser('dark.vador'), 'main'));

        $user = new UserProvider($tokenStorage)();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('dark.vador', $user->username);
        $this->assertSame(InMemoryUser::class, $user->fqdn);
        $this->assertNull($user->impersonator);
    }

    public function testTheImpersonatorIsKeptApart(): void
    {
        $original = new UsernamePasswordToken($this->createUser('dark.vador'), 'main');
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new SwitchUserToken($this->createUser('second_user'), 'main', [], $original));

        $user = new UserProvider($tokenStorage)();

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('second_user', $user->username, 'The impersonated user is blamed, without suffix.');
        $this->assertSame(InMemoryUser::class, $user->fqdn);
        $this->assertInstanceOf(User::class, $user->impersonator);
        $this->assertNull($user->impersonator->identifier, 'A user without getId() has no identifier.');
        $this->assertSame('dark.vador', $user->impersonator->username);
        $this->assertSame(InMemoryUser::class, $user->impersonator->fqdn);
    }

    public function testNoUserWithoutToken(): void
    {
        $this->assertNull(new UserProvider(new TokenStorage())());
    }

    public function testBlameImpersonator(): void
    {
        $auditingServices = [
            Post::class => $this->provider->getAuditingServiceForEntity(Post::class),
        ];

        $user = $this->createUser('dark.vador');
        $secondUser = $this->createUser('second_user');

        $firewallName = 'main';
        $userToken = new UsernamePasswordToken($user, $firewallName, $user->getRoles());
        $token = new SwitchUserToken($secondUser, $firewallName, $secondUser->getRoles(), $userToken);

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
        $this->assertSame('second_user', $entries[0]->username);
        $this->assertSame(['username' => 'dark.vador', 'fqdn' => InMemoryUser::class], $entries[0]->impersonator, 'The impersonator is written apart.');
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }

    private function createUser(string $username): UserInterface
    {
        return new InMemoryUser(
            $username,
            '$argon2id$v=19$m=65536,t=4,p=1$g1yZVCS0GJ32k2fFqBBtqw$359jLODXkhqVWtD/rf+CjiNz9r/kIvhJlenPBnW851Y',
            []
        );
    }
}
