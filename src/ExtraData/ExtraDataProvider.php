<?php

declare(strict_types=1);

namespace DH\AuditorBundle\ExtraData;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Default extra_data provider for auditor-bundle.
 *
 * Captures contextual HTTP request information (route name and route parameters)
 * and attaches it to every audit entry via the `extra_data` JSON column.
 *
 * Return null outside of an HTTP request context (e.g. console commands) so
 * that `extra_data` is left empty instead of storing an empty array.
 *
 * @see https://github.com/DamienHarper/auditor-bundle/issues/594
 */
final readonly class ExtraDataProvider
{
    public function __construct(private RequestStack $requestStack) {}

    public function __invoke(): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request instanceof Request) {
            return null;
        }

        $route = $request->attributes->get('_route');
        if (null === $route) {
            return null;
        }

        return ['route' => $route];
    }
}
