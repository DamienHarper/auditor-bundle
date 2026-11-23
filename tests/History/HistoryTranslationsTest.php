<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\History;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;

/**
 * @internal
 */
#[Small]
final class HistoryTranslationsTest extends TestCase
{
    private const array PARAMETERS = ['count' => 3, 'entity' => 'E', 'field' => 'F', 'label' => 'L', 'old' => 'O', 'new' => 'N', 'names' => 'a', 'id' => '7'];

    #[DataProvider('provideEveryLocaleTranslatesEveryKeyCases')]
    public function testEveryLocaleTranslatesEveryKey(string $locale): void
    {
        $reference = $this->messages('en');
        $messages = $this->messages($locale);

        $this->assertSame(array_keys($reference), array_keys($messages), 'Same keys, in the same order, as the English file.');

        foreach ($messages as $key => $message) {
            $formatter = \MessageFormatter::create($locale, $message);
            $this->assertNotNull($formatter, \sprintf('"%s" is a valid ICU message.', $key));
            $this->assertNotFalse($formatter->format(self::PARAMETERS), \sprintf('"%s" formats.', $key));

            preg_match_all('/\{(\w+)[},]/', $reference[$key], $expected);
            preg_match_all('/\{(\w+)[},]/', $message, $actual);
            $this->assertSame(array_unique($expected[1]), array_values(array_intersect(array_unique($expected[1]), $actual[1])), \sprintf('"%s" keeps the parameters of the English message.', $key));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideEveryLocaleTranslatesEveryKeyCases(): iterable
    {
        foreach (['de', 'en', 'es', 'et', 'fr', 'it', 'nl', 'ru', 'uk'] as $locale) {
            yield $locale => [$locale];
        }
    }

    /**
     * @return array<string, string>
     */
    private function messages(string $locale): array
    {
        /** @var array<string, string> */
        return new YamlFileLoader()->load(\dirname(__DIR__, 2).'/src/translations/dh_auditor_history+intl-icu.'.$locale.'.yaml', $locale, 'dh_auditor_history')->all('dh_auditor_history');
    }
}
