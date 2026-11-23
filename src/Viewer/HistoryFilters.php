<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Viewer;

use DH\Auditor\History\BlameOrigin;
use DH\Auditor\Model\TransactionType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Filters of a history page, read from the query string: who (user id, or "__anonymous__"), what (entry type),
 * field, origin, when (days), and whether the attached entities are included.
 */
final readonly class HistoryFilters
{
    public const string ANONYMOUS = '__anonymous__';

    public const array PERIODS = [7, 30, 90];

    public function __construct(
        public ?string $who = null,
        public ?string $what = null,
        public ?string $field = null,
        public ?string $origin = null,
        public ?int $days = null,
        public bool $attached = true,
        public ?string $entity = null,
    ) {}

    /**
     * @throws BadRequestHttpException on an unknown type, origin or period
     */
    public static function fromRequest(Request $request): self
    {
        $value = static fn (string $name): ?string => '' === ($v = $request->query->getString($name)) ? null : $v;

        $what = $value('what');
        if (null !== $what && !\in_array($what, array_map(static fn (TransactionType $type): string => $type->value, TransactionType::cases()), true)) {
            throw new BadRequestHttpException(\sprintf('Unknown type "%s".', $what));
        }

        $origin = $value('origin');
        if (null !== $origin && !BlameOrigin::tryFrom($origin) instanceof BlameOrigin) {
            throw new BadRequestHttpException(\sprintf('Unknown origin "%s".', $origin));
        }

        $days = $value('when');
        if (null !== $days && !\in_array((int) $days, self::PERIODS, true)) {
            throw new BadRequestHttpException(\sprintf('Unknown period "%s".', $days));
        }

        return new self($value('who'), $what, $value('field'), $origin, null === $days ? null : (int) $days, '0' !== $request->query->getString('attached', '1'), $value('on'));
    }

    /**
     * Query string of the filters that differ from the defaults.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'who' => $this->who,
            'what' => $this->what,
            'field' => $this->field,
            'origin' => $this->origin,
            'when' => null === $this->days ? null : (string) $this->days,
            'attached' => $this->attached ? null : '0',
            'on' => $this->entity,
        ], static fn (?string $value): bool => null !== $value);
    }

    public function isFiltered(): bool
    {
        return [] !== array_diff_key($this->toQuery(), ['attached' => true]);
    }
}
