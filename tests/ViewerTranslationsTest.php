<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests;

use DH\Auditor\History\BlameOrigin;
use DH\Auditor\Model\TransactionType;
use DH\AuditorBundle\Enum\EntitySort;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * The "auditor" domain of the viewer: every locale has every key of the English file, with its parameters, and the
 * keys the templates build (types, origins, sorts) exist.
 *
 * @internal
 */
#[Small]
final class ViewerTranslationsTest extends TestCase
{
    private const array LOCALES = ['de', 'en', 'es', 'et', 'fr', 'it', 'nl', 'ru', 'uk'];

    #[DataProvider('provideEveryLocaleTranslatesEveryKeyCases')]
    public function testEveryLocaleTranslatesEveryKey(string $locale): void
    {
        $reference = $this->messages('en');
        $messages = $this->messages($locale);

        $this->assertSame(array_keys($reference), array_keys($messages), 'Same keys, in the same order, as the English file.');

        $translator = new Translator($locale);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $this->path($locale), $locale, 'auditor');

        foreach ($messages as $key => $message) {
            preg_match_all('/%\w+%/', $reference[$key], $expected);
            foreach (array_unique($expected[0]) as $parameter) {
                if ('%count%' !== $parameter || !str_contains($message, '|')) {
                    $this->assertStringContainsString($parameter, $message, \sprintf('"%s" keeps %s.', $key, $parameter));
                }
            }

            if (str_contains($reference[$key], '|')) {
                // the time_ago messages are given at least 1 minute, hour or day
                foreach (str_starts_with($key, 'time_ago.') && !str_starts_with($key, 'time_ago.seconds') ? [1, 2, 5, 21] : [0, 1, 2, 5, 21] as $count) {
                    $this->assertNotSame('', $translator->trans($key, ['%count%' => $count, '%formatted_count%' => $count, '%days%' => 1, '%current%' => 1, '%total%' => 1], 'auditor'), \sprintf('"%s" has a form for %d.', $key, $count));
                }
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEveryLocaleTranslatesEveryKeyCases(): iterable
    {
        foreach (self::LOCALES as $locale) {
            yield $locale => [$locale];
        }
    }

    public function testKeysBuiltByTheTemplatesExist(): void
    {
        $keys = array_keys($this->messages('en'));
        $built = [
            ...array_map(static fn (TransactionType $type): string => 'audit.filter.type.'.$type->value, TransactionType::cases()),
            ...array_map(static fn (BlameOrigin $origin): string => 'audit.history.origin.'.$origin->value, BlameOrigin::cases()),
            ...array_map(static fn (BlameOrigin $origin): string => 'audit.history.origin_help.'.$origin->value, BlameOrigin::cases()),
            ...array_map(static fn (EntitySort $sort): string => 'audit.sort.'.$sort->value, EntitySort::cases()),
        ];

        $this->assertSame([], array_values(array_diff($built, $keys)));
    }

    /**
     * @return array<string, string>
     */
    private function messages(string $locale): array
    {
        /** @var array<string, string> */
        return new YamlFileLoader()->load($this->path($locale), $locale, 'auditor')->all('auditor');
    }

    private function path(string $locale): string
    {
        return __DIR__.'/../src/translations/auditor.'.$locale.'.yaml';
    }
}
