<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\Provider;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration;
use DH\Auditor\Provider\ProviderInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Second minimal stub provider for multi-provider tests.
 */
final class StubProviderB implements ProviderInterface
{
    private ?Auditor $auditor = null;

    public function setAuditor(Auditor $auditor): void
    {
        $this->auditor = $auditor;
    }

    public function getAuditor(): Auditor
    {
        return $this->auditor ?? new Auditor(new Configuration([]), new EventDispatcher());
    }

    public function getConfiguration(): mixed
    {
        return null;
    }

    public function isRegistered(): bool
    {
        return null !== $this->auditor;
    }

    public function registerStorageService(mixed $service): static
    {
        return $this;
    }

    public function registerAuditingService(mixed $service): static
    {
        return $this;
    }

    public function persist(mixed $payload): void {}

    /**
     * @return array<string, mixed>
     */
    public function getStorageServices(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAuditingServices(): array
    {
        return [];
    }

    public function supportsStorage(): bool
    {
        return true;
    }

    public function supportsAuditing(): bool
    {
        return true;
    }

    public function isAuditable(string $entity): bool
    {
        return false;
    }

    public function reset(): void {}
}
