<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\Model\Entry;
use DH\Auditor\Provider\Doctrine\DoctrineProvider;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Author;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Comment;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\Helper\UrlHelper;
use DH\AuditorBundle\Twig\ViewableEntityExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Twig\Environment;

/**
 * Association/dissociation diffs must only link to the audit stream of a related entity
 * when that stream can actually be opened: the entity is auditable and the role checker
 * grants the "view" scope. Covers legacy (schema v1), migrated and native schema v2 entries.
 *
 * @internal
 */
#[Small]
final class ViewableEntityExtensionTest extends KernelTestCase
{
    private const string FORMAT_V1_LEGACY = 'v1 legacy';

    private const string FORMAT_V2_MIGRATED = 'v2 migrated';

    private const string FORMAT_V2_NATIVE = 'v2 native';

    /**
     * @param class-string $related
     * @param list<string> $roles   empty list means no authenticated user
     */
    #[DataProvider('provideAssociationDiffLinksOnlyToViewableEntitiesCases')]
    public function testAssociationDiffLinksOnlyToViewableEntities(string $format, string $related, array $roles, bool $expectLink): void
    {
        $this->bootWithConfiguration($roles);

        $postStream = '/audit/'.UrlHelper::namespaceToParam(Post::class).'/1';
        $relatedStream = '/audit/'.UrlHelper::namespaceToParam($related).'/2';
        $relatedText = mb_substr($related, (int) mb_strrpos($related, '\\') + 1).' #2';

        foreach (['associate', 'dissociate'] as $type) {
            foreach (['target', 'source'] as $relatedSide) {
                $crawler = $this->renderEntry($format, $type, $related, $relatedSide);
                $context = \sprintf('%s %s with %s as %s', $format, $type, $related, $relatedSide);

                $this->assertCount(1, $crawler->filter(\sprintf('table a[href="%s"]', $postStream)), $context.': the viewable side is linked');
                $this->assertCount($expectLink ? 1 : 0, $crawler->filter(\sprintf('table a[href="%s"]', $relatedStream)), $context.': link to the related entity');

                if (!$expectLink) {
                    $plain = $crawler->filterXPath(\sprintf('//table//span[@title="%s"]', $related));
                    $this->assertCount(1, $plain, $context.': the related entity is rendered as plain text');
                    $this->assertSame($relatedText, mb_trim($plain->text()), $context.': the related entity is still identified');
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string, class-string, list<string>, bool}>
     */
    public static function provideAssociationDiffLinksOnlyToViewableEntitiesCases(): iterable
    {
        foreach ([self::FORMAT_V1_LEGACY, self::FORMAT_V2_MIGRATED, self::FORMAT_V2_NATIVE] as $format) {
            yield $format.' / auditable, no role required' => [$format, Tag::class, ['ROLE_USER'], true];

            yield $format.' / not auditable' => [$format, Comment::class, ['ROLE_USER'], false];

            yield $format.' / auditable, role not granted' => [$format, Author::class, ['ROLE_USER'], false];

            yield $format.' / auditable, role granted' => [$format, Author::class, ['ROLE_ADMIN'], true];

            yield $format.' / auditable, roles required, no user' => [$format, Author::class, [], false];
        }
    }

    public function testIsViewable(): void
    {
        $this->bootWithConfiguration(['ROLE_USER']);
        $extension = self::getContainer()->get(ViewableEntityExtension::class);

        $this->assertTrue($extension->isViewable(Tag::class));
        $this->assertFalse($extension->isViewable(Comment::class));
        $this->assertFalse($extension->isViewable(Author::class));
        $this->assertFalse($extension->isViewable('App\Entity\RemovedFromConfiguration'));
    }

    /**
     * @param list<string> $roles
     */
    private function bootWithConfiguration(array $roles): void
    {
        self::bootKernel();
        $container = self::getContainer();

        /** @var DoctrineProvider $provider */
        $provider = $container->get(DoctrineProvider::class);
        $provider->getConfiguration()->setEntities([
            Post::class => ['enabled' => true],
            Tag::class => ['enabled' => true],
            Author::class => ['enabled' => true, 'roles' => ['view' => ['ROLE_ADMIN']]],
        ]);

        if ([] !== $roles) {
            $user = new InMemoryUser('auditor.viewer', null, $roles);

            /** @var TokenStorageInterface $tokenStorage */
            $tokenStorage = $container->get('security.token_storage');
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }
    }

    /**
     * @param class-string $related
     */
    private function renderEntry(string $format, string $type, string $related, string $relatedSide): Crawler
    {
        $post = ['id' => 1, 'class' => Post::class, 'label' => 'Post#1', 'table' => 'post'];
        $other = ['id' => 2, 'class' => $related, 'label' => 'Related#2', 'table' => 'related'];

        if (self::FORMAT_V2_NATIVE === $format) {
            $post['field'] = 'related';
            $other['field'] = 'posts';
        }

        $diffs = 'target' === $relatedSide
            ? ['source' => $post, 'target' => $other, 'is_owning_side' => true]
            : ['source' => $other, 'target' => $post, 'is_owning_side' => false];
        $diffs[self::FORMAT_V1_LEGACY === $format ? 'table' : 'join_table'] = 'post__related';

        $row = [
            'id' => 1,
            'schema_version' => self::FORMAT_V1_LEGACY === $format ? 1 : 2,
            'type' => $type,
            'object_id' => (string) $diffs['source']['id'],
            'diffs' => json_encode($diffs, JSON_THROW_ON_ERROR),
            'created_at' => new \DateTimeImmutable(),
        ];
        $row[self::FORMAT_V1_LEGACY === $format ? 'transaction_hash' : 'transaction_id'] = self::FORMAT_V1_LEGACY === $format
            ? str_repeat('a', 40)
            : '01JABCDEFGHJKMNPQRSTVWXYZ0';

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $crawler = new Crawler();
        $crawler->addHtmlContent($twig->render('@DHAuditor/Audit/entry.html.twig', [
            'entry' => Entry::fromArray($row),
            'entity' => $diffs['source']['class'],
        ]));

        return $crawler;
    }
}
