<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Controller;

use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Traits\Schema\BlogSchemaSetupTrait;
use DH\AuditorBundle\Controller\ExportController;
use DH\AuditorBundle\Helper\UrlHelper;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\AbstractBrowser;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * @internal
 *
 * @see ExportController
 */
#[Small]
final class ExportControllerTest extends WebTestCase
{
    use BlogSchemaSetupTrait;

    private AbstractBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();

        $this->createAndInitDoctrineProvider();
        $this->configureEntities();
        $this->setupEntitySchemas();
        $this->setupAuditSchemas();
        $this->setupEntities();
    }

    public function testExportNdjsonDefaultsAllEntities(): void
    {
        $this->client->request('GET', '/audit/export');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/x-ndjson', (string) $response->headers->get('Content-Type'));

        $content = $this->client->getInternalResponse()->getContent();
        $this->assertNotEmpty($content, 'Response body must not be empty');

        foreach (array_filter(explode("\n", mb_trim($content))) as $line) {
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Each NDJSON line must decode to an array');
            $this->assertArrayHasKey('type', $decoded);
            $this->assertArrayHasKey('object_id', $decoded);
        }
    }

    public function testExportJsonFormat(): void
    {
        $this->client->request('GET', '/audit/export?format=json');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));

        $content = $this->client->getInternalResponse()->getContent();
        $decoded = json_decode($content, true);
        $this->assertIsArray($decoded, 'JSON format must return a JSON array');
        $this->assertNotEmpty($decoded, 'JSON array must not be empty');
        $this->assertArrayHasKey('type', $decoded[0]);
    }

    public function testExportCsvFormat(): void
    {
        $this->client->request('GET', '/audit/export?format=csv');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));

        $content = $this->client->getInternalResponse()->getContent();
        $lines = array_filter(explode("\n", mb_trim($content)));
        $this->assertGreaterThanOrEqual(2, \count($lines), 'CSV must have at least a header row and one data row');

        $header = str_getcsv(array_values($lines)[0], escape: '\\');
        $this->assertContains('type', $header, 'CSV header must contain "type"');
        $this->assertContains('object_id', $header, 'CSV header must contain "object_id"');
    }

    public function testExportWithEntityFilter(): void
    {
        $entityParam = UrlHelper::namespaceToParam(Author::class);
        $this->client->request('GET', '/audit/export?entity='.$entityParam);
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());

        $content = $this->client->getInternalResponse()->getContent();
        $this->assertNotEmpty($content);

        foreach (array_filter(explode("\n", mb_trim($content))) as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $this->assertArrayHasKey('type', $row);
            $this->assertArrayHasKey('object_id', $row);
        }
    }

    public function testExportAnonymize(): void
    {
        $this->client->request('GET', '/audit/export?anonymize=1&format=ndjson');
        $response = $this->client->getResponse();

        $this->assertSame(200, $response->getStatusCode());

        $content = $this->client->getInternalResponse()->getContent();
        $this->assertNotEmpty($content);

        foreach (array_filter(explode("\n", mb_trim($content))) as $line) {
            $row = json_decode($line, true);
            $this->assertIsArray($row);
            $this->assertNull($row['blame_id'], 'blame_id must be null when anonymized');
            $this->assertNull($row['blame_user'], 'blame_user must be null when anonymized');
        }
    }

    public function testExportReturns400ForInvalidFormat(): void
    {
        $this->client->request('GET', '/audit/export?format=xml');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testExportReturns404ForUnknownEntity(): void
    {
        $this->client->request('GET', '/audit/export?entity=No-Such-Entity-Class');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testExportReturns403WhenRoleDenied(): void
    {
        $this->login(['ROLE_USER']);
        $entityParam = UrlHelper::namespaceToParam(Author::class);
        $this->client->request('GET', '/audit/export?entity='.$entityParam);

        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testContentDispositionHeaderPresent(): void
    {
        $this->client->request('GET', '/audit/export');
        $disposition = $this->client->getResponse()->headers->get('Content-Disposition');

        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', (string) $disposition);
        $this->assertStringContainsString('audit-export-', (string) $disposition);
        $this->assertStringContainsString('.ndjson', (string) $disposition);
    }

    private function createAndInitDoctrineProvider(): void
    {
        $this->provider = self::getContainer()->get(DoctrineProvider::class);
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
}
