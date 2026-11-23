<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\History;

use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Post;
use DH\Auditor\Tests\Provider\Doctrine\Fixtures\Entity\Standard\Blog\Tag;
use DH\AuditorBundle\History\ValueFormatter;
use DH\AuditorBundle\Tests\Fixtures\History\HistoryFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[Small]
final class ValueFormatterTest extends TestCase
{
    use HistoryFixtures;

    #[DataProvider('provideFormatCases')]
    public function testFormat(string $locale, string $field, mixed $value, string $expected): void
    {
        $formatter = new ValueFormatter($this->registry(), self::translator($locale));

        $this->assertSame($expected, $formatter->format(Post::class, $field, $value));
    }

    /**
     * @return iterable<string, array{string, string, mixed, string}>
     */
    public static function provideFormatCases(): iterable
    {
        yield 'translatable enum' => ['fr', 'status', 'published', 'Publié'];

        yield 'enum without label' => ['en', 'kind', 'news', 'news'];

        yield 'unknown enum case' => ['en', 'kind', 'gone', 'gone'];

        yield 'int enum stored as a string' => ['en', 'priority', '2', 'High'];

        yield 'int enum stored as an int' => ['en', 'priority', 1, 'Low'];

        yield 'int enum, not a number' => ['en', 'priority', 'x', 'x'];

        yield 'string enum stored as an int' => ['en', 'kind', 7, '7'];

        yield 'numeric string enum stored as an int' => ['en', 'status', 3, 'Legacy'];

        yield 'boolean stored as a string' => ['en', 'featured', 'false', 'no'];

        yield 'boolean stored as a letter' => ['fr', 'featured', 't', 'oui'];

        yield 'boolean not readable' => ['en', 'featured', 'maybe', 'maybe'];

        yield 'time' => ['en', 'startsAt', '18:41:00', '6:41 PM'];

        yield 'date stored as a timestamp' => ['en', 'deadline', 1_764_460_800, '1764460800'];

        yield 'boolean true' => ['fr', 'featured', true, 'oui'];

        yield 'boolean stored as an integer' => ['en', 'featured', 0, 'no'];

        yield 'datetime' => ['en', 'publishedAt', '2026-11-22 18:41:00', 'Nov 22, 2026, 6:41 PM'];

        yield 'date' => ['fr', 'deadline', '2026-11-30', '30 nov. 2026'];

        yield 'unreadable date' => ['en', 'deadline', 'not a date', 'not a date'];

        yield 'empty' => ['fr', 'title', null, 'vide'];

        yield 'empty string' => ['en', 'title', '', 'empty'];

        yield 'string' => ['en', 'title', 'Why audit?', 'Why audit?'];

        yield 'number' => ['en', 'title', 12.5, '12.5'];

        yield 'unmapped field' => ['en', 'unknown', 'raw', 'raw'];

        yield 'array' => ['en', 'unknown', ['a' => 1], '{"a":1}'];

        yield 'array with invalid UTF-8' => ['en', 'unknown', ['a' => "b\xB1"], "{\"a\":\"b\u{FFFD}\"}"];
    }

    public function testRawValueWithoutMetadata(): void
    {
        $formatter = new ValueFormatter($this->registry(), self::translator());

        $this->assertSame('1', $formatter->format(Tag::class, 'featured', 1), 'An entity unknown to Doctrine keeps its raw value.');
        $this->assertSame('yes', $formatter->format(Tag::class, 'featured', true), 'A boolean is a boolean, whatever its mapping.');
    }

    public function testTheLabelWins(): void
    {
        $formatter = new ValueFormatter($this->registry(), self::translator());

        $this->assertSame('Alice', $formatter->format(Post::class, 'author', ['class' => 'App\Entity\Author', 'id' => 1, 'label' => 'Alice'], 'Alice'));
        $this->assertSame('Ready', $formatter->format(Post::class, 'status', 'published', 'Ready'), 'A #[DiffLabel] label.');
        $this->assertSame('Alice', $formatter->format(Post::class, 'author', ['class' => 'App\Entity\Author', 'id' => 1, 'label' => 'Alice']), 'An association summary is read through its label.');
        $this->assertSame('Author #1', $formatter->format(Post::class, 'author', ['class' => 'App\Entity\Author', 'id' => 1]), 'An association summary without label.');
    }

    public function testUnreadableValue(): void
    {
        $value = array_reduce(range(1, 600), static fn (mixed $carry): array => [$carry], 1);

        $this->assertSame('unreadable value', new ValueFormatter($this->registry(), self::translator())->format(Post::class, 'unknown', $value), 'Deeper than JSON can encode.');
    }

    public function testType(): void
    {
        $formatter = new ValueFormatter($this->registry(), self::translator());

        $this->assertSame('enum', $formatter->typeOf(Post::class, 'status'));
        $this->assertSame('boolean', $formatter->typeOf(Post::class, 'featured'));
        $this->assertSame('text', $formatter->typeOf(Post::class, 'body'));
        $this->assertNull($formatter->typeOf(Post::class, 'unknown'));
        $this->assertNull($formatter->typeOf(Tag::class, 'title'));
    }

    #[DataProvider('provideTruncateCases')]
    public function testTruncate(string $text, string $expected): void
    {
        $this->assertSame($expected, ValueFormatter::truncate($text, 10));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideTruncateCases(): iterable
    {
        yield 'short' => ['Relu', 'Relu'];

        yield 'exact' => ['0123456789', '0123456789'];

        yield 'cut on a word' => ['Relu, merci beaucoup', 'Relu,…'];

        yield 'cut in a word' => ['Anticonstitutionnellement', 'Anticonst…'];

        yield 'multibyte' => ['ééééééééééé', 'ééééééééé…'];

        yield 'line breaks' => ["Relu\nmerci", 'Relu merci'];
    }
}
