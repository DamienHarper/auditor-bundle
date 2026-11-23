<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\History;

use DH\AuditorBundle\History\TranslatedFieldLabelResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

/**
 * @internal
 */
#[Small]
final class TranslatedFieldLabelResolverTest extends TestCase
{
    public function testTranslatedLabel(): void
    {
        $translator = new Translator('fr');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['App\Entity\Property.price' => 'Prix'], 'fr', TranslatedFieldLabelResolver::DOMAIN);

        $resolver = new TranslatedFieldLabelResolver($translator);

        $this->assertSame('Prix', $resolver->resolve('App\Entity\Property', 'price'));
        $this->assertSame('Surface area', $resolver->resolve('App\Entity\Property', 'surfaceArea'), 'A field without translation is humanized.');
    }

    #[DataProvider('provideHumanizeCases')]
    public function testHumanize(string $field, string $expected): void
    {
        $this->assertSame($expected, new TranslatedFieldLabelResolver()->resolve('App\Entity\Property', $field));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideHumanizeCases(): iterable
    {
        yield 'camel case' => ['createdAt', 'Created at'];

        yield 'snake case' => ['created_at', 'Created at'];

        yield 'embeddable' => ['address.zipCode', 'Address zip code'];

        yield 'one word' => ['price', 'Price'];
    }
}
