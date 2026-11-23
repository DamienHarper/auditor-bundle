<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\AuditorBundle\Tests\History\ValueFormatterTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatableInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Formats the raw values of audit diffs for a reader.
 *
 * Diffs hold the values as the database stores them (Type::convertToDatabaseValue()): the Doctrine mapping of the
 * field tells how to read them back. An enum gives its label when it implements TranslatableInterface (its value
 * otherwise), a date the medium format of the locale, a boolean "yes" or "no", no value "empty". A field without
 * mapping keeps its raw value.
 *
 * @see ValueFormatterTest
 */
final readonly class ValueFormatter
{
    /**
     * Length of a value in a phrase: longer texts are cut.
     */
    public const int MAX_LENGTH = 80;

    // the mutable date types are deprecated by DBAL 4 but still mapped by applications: their names are spelled out
    private const array DATE_TYPES = ['date', Types::DATE_IMMUTABLE];

    private const array TIME_TYPES = ['time', Types::TIME_IMMUTABLE];

    private const array DATETIME_TYPES = ['datetime', Types::DATETIME_IMMUTABLE, 'datetimetz', Types::DATETIMETZ_IMMUTABLE, 'datetime_utc', 'datetime_utc_immutable'];

    public function __construct(private ManagerRegistry $registry, private TranslatorInterface $translator) {}

    /**
     * Formats a value of a field. A label written with the value (#[DiffLabel], association summary) wins.
     *
     * @param class-string|string $entity
     */
    public function format(string $entity, string $field, mixed $value, ?string $label = null): string
    {
        if (null !== $label && '' !== $label) {
            return $label;
        }

        if (null === $value || '' === $value) {
            return $this->trans('value.empty');
        }

        if (\is_array($value)) {
            return $this->formatArray($value);
        }

        if (\is_bool($value)) {
            return $this->trans($value ? 'value.yes' : 'value.no');
        }

        [$mapping, $connection] = $this->mapping($entity, $field);

        return match (true) {
            !$mapping instanceof FieldMapping => $this->string($value),
            null !== $mapping->enumType => $this->formatEnum($mapping->enumType, $value),
            Types::BOOLEAN === $mapping->type => $this->trans((bool) $value ? 'value.yes' : 'value.no'),
            $connection instanceof Connection && \in_array($mapping->type, [...self::DATE_TYPES, ...self::TIME_TYPES, ...self::DATETIME_TYPES], true) => $this->formatDate($mapping->type, $value, $connection),
            default => $this->string($value),
        };
    }

    /**
     * Kind of value of a field: "enum" for an enum, otherwise the Doctrine type ("boolean", "text"…); null for a field
     * Doctrine does not map.
     *
     * @param class-string|string $entity
     */
    public function typeOf(string $entity, string $field): ?string
    {
        [$mapping] = $this->mapping($entity, $field);
        if (!$mapping instanceof FieldMapping) {
            return null;
        }

        return null === $mapping->enumType ? $mapping->type : 'enum';
    }

    /**
     * Position of a field in the mapping of its entity: the fields, then the associations, in the order Doctrine maps
     * them. PHP_INT_MAX for a field Doctrine does not map.
     *
     * @param class-string|string $entity
     */
    public function position(string $entity, string $field): int
    {
        $metadata = $this->metadata($entity);
        if (!$metadata instanceof ClassMetadata) {
            return PHP_INT_MAX;
        }

        $position = array_search($field, [...$metadata->getFieldNames(), ...$metadata->getAssociationNames()], true);

        return false === $position ? PHP_INT_MAX : $position;
    }

    /**
     * Cuts a text to $max characters, on a word boundary when one is close enough, and puts it on one line.
     */
    public static function truncate(string $text, int $max = self::MAX_LENGTH): string
    {
        $text = mb_trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($cut, ' ');
        if (false !== $space && $space >= intdiv($max, 2)) {
            $cut = mb_substr($cut, 0, $space);
        }

        return mb_rtrim($cut).'…';
    }

    /**
     * @param class-string|string $entity
     *
     * @return array{0: ?FieldMapping, 1: ?Connection}
     */
    private function mapping(string $entity, string $field): array
    {
        $metadata = $this->metadata($entity);
        if (!$metadata instanceof ClassMetadata || !$metadata->hasField($field)) {
            return [null, null];
        }

        $manager = $this->registry->getManagerForClass($metadata->getName());

        return [$metadata->getFieldMapping($field), $manager instanceof EntityManagerInterface ? $manager->getConnection() : null];
    }

    /**
     * @return null|ClassMetadata<object>
     */
    private function metadata(string $entity): ?ClassMetadata
    {
        if (!class_exists($entity)) {
            return null;
        }

        $manager = $this->registry->getManagerForClass($entity);
        if (!$manager instanceof EntityManagerInterface) {
            return null;
        }

        return $manager->getClassMetadata($entity);
    }

    /**
     * @param class-string<\UnitEnum> $enumType
     */
    private function formatEnum(string $enumType, mixed $value): string
    {
        $case = is_subclass_of($enumType, \BackedEnum::class) && (\is_int($value) || \is_string($value)) ? $enumType::tryFrom($value) : null;

        return match (true) {
            $case instanceof TranslatableInterface => $case->trans($this->translator, $this->locale()),
            null === $case => $this->string($value),
            default => (string) $case->value,
        };
    }

    private function formatDate(string $type, mixed $value, Connection $connection): string
    {
        try {
            $date = $connection->convertToPHPValue($value, $type);
        } catch (\Throwable) {
            return $this->string($value);
        }

        if (!$date instanceof \DateTimeInterface) {
            return $this->string($value);
        }

        $formatter = new \IntlDateFormatter(
            $this->locale(),
            \in_array($type, self::TIME_TYPES, true) ? \IntlDateFormatter::NONE : \IntlDateFormatter::MEDIUM,
            \in_array($type, self::DATE_TYPES, true) ? \IntlDateFormatter::NONE : \IntlDateFormatter::SHORT,
            $date->getTimezone(),
        );
        $formatted = $formatter->format($date);

        return false === $formatted ? $this->string($value) : str_replace(["\u{202F}", "\u{A0}"], ' ', $formatted);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function formatArray(array $value): string
    {
        if (\is_string($value['label'] ?? null) && '' !== $value['label']) {
            return $value['label'];
        }

        if (\is_string($value['class'] ?? null) && \array_key_exists('id', $value)) {
            $short = mb_strrchr($value['class'], '\\');

            return (false === $short ? $value['class'] : mb_substr($short, 1)).' #'.$this->string($value['id']);
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) ?: '';
    }

    private function string(mixed $value): string
    {
        return match (true) {
            \is_string($value) => $value,
            \is_int($value), \is_float($value) => (string) $value,
            \is_bool($value) => $value ? '1' : '0',
            $value instanceof \Stringable => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        };
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], HistoryPhraser::DOMAIN);
    }

    private function locale(): string
    {
        return $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : \Locale::getDefault();
    }
}
