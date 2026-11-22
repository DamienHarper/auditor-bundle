<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Twig;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use PHPUnit\Framework\Attributes\Small;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * @internal
 */
#[Small]
final class ViewerUrlExtensionTest extends KernelTestCase
{
    private const string POST_PARAM = 'DH-Auditor-Tests-Provider-Doctrine-Fixtures-Entity-Standard-Blog-Post';

    public function testEntityUrl(): void
    {
        $post = new Post();
        new \ReflectionProperty(Post::class, 'id')->setValue($post, 5);

        $this->assertSame('/audit/'.self::POST_PARAM.'/5', $this->render('{{ dh_auditor_entity_url(post) }}', ['post' => $post]));
        $this->assertSame('/audit/'.self::POST_PARAM, $this->render('{{ dh_auditor_entity_url(class) }}', ['class' => Post::class]));
        $this->assertSame(
            '/audit/'.self::POST_PARAM.'/5?type=update',
            $this->render("{{ dh_auditor_entity_url(class, 5, {type: 'update'}) }}", ['class' => Post::class]),
        );
        $this->assertSame(
            'http://localhost/audit/'.self::POST_PARAM.'/5',
            $this->render('{{ dh_auditor_entity_url(post, absolute: true) }}', ['post' => $post]),
        );
    }

    public function testNullWhenNotAudited(): void
    {
        $this->assertSame(
            'none',
            $this->render("{% set url = dh_auditor_entity_url('App\\\\Entity\\\\NotAudited') %}{{ url is null ? 'none' : url }}"),
        );
    }

    public function testTransactionAndUserUrls(): void
    {
        $this->assertSame('/audit/transaction/01JABCDEFGHJKMNPQRSTVWXYZ0', $this->render("{{ dh_auditor_transaction_url('01JABCDEFGHJKMNPQRSTVWXYZ0') }}"));
        $this->assertSame('/audit/user/42', $this->render('{{ dh_auditor_user_url(42) }}'));
        $this->assertSame('http://localhost/audit/user/42', $this->render('{{ dh_auditor_user_url(42, true) }}'));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $template, array $context = []): string
    {
        self::bootKernel();

        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig->createTemplate($template)->render($context);
    }
}
