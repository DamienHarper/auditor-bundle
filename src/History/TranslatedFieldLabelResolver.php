<?php

declare(strict_types=1);

namespace DH\AuditorBundle\History;

use DH\Auditor\Contract\FieldLabelResolverInterface;
use DH\AuditorBundle\Tests\History\TranslatedFieldLabelResolverTest;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Labels of the fields of audited entities, from the "dh_auditor_fields" translation domain, keyed by
 * "FQCN.field" (for instance "App\Entity\Property.price: Price"). A field without translation is humanized
 * ("createdAt" and "created_at" both give "Created at").
 *
 * @see TranslatedFieldLabelResolverTest
 */
final readonly class TranslatedFieldLabelResolver implements FieldLabelResolverInterface
{
    public const string DOMAIN = 'dh_auditor_fields';

    public function __construct(private ?TranslatorInterface $translator = null) {}

    public function resolve(string $entity, string $field): string
    {
        $key = $entity.'.'.$field;
        if ($this->translator instanceof TranslatorInterface) {
            $label = $this->translator->trans($key, [], self::DOMAIN);
            if ($label !== $key) {
                return $label;
            }
        }

        return self::humanize($field);
    }

    public static function humanize(string $field): string
    {
        $words = mb_strtolower(mb_trim((string) preg_replace(['/([a-z\d])([A-Z])/', '/[_.]+/'], ['$1 $2', ' '], $field)));

        return '' === $words ? $field : mb_strtoupper(mb_substr($words, 0, 1)).mb_substr($words, 1);
    }
}
