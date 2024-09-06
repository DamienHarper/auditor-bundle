<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\User;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Traits\ReaderTrait;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\DHAuditorBundle;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\ApplicationTester;

/**
 * @internal
 *
 * @small
 *
 * @coversNothing
 */
final class ConsoleUserProviderTest extends KernelTestCase
{
    use BlogSchemaSetupTrait;
    use ReaderTrait;

    private DoctrineProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

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

        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new ApplicationTester($application);

        $tester->run(['app:post:create']);

        $tester->assertCommandIsSuccessful('Expect it to run');

        $this->flushAll($auditingServices);

        // get history
        $entries = $this->createReader()->createQuery(Post::class)->execute();
        self::assertNotEmpty($entries, 'There are audit entries');
        self::assertSame('app:post:create', $entries[0]->getUsername(), 'Username is OK');
        self::assertSame('command', $entries[0]->getUserId(), 'User ID is OK');
    }

    protected function getBundleClass()
    {
        return DHAuditorBundle::class;
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
    }
}
