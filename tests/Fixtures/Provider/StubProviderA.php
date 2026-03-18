<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Tests\Fixtures\Provider;

use DH\Auditor\Auditor;
use DH\Auditor\Configuration;
use DH\Auditor\Event\LifecycleEvent;
use DH\Auditor\Provider\ConfigurationInterface;
use DH\Auditor\Provider\ProviderInterface;
use DH\Auditor\Provider\Service\AuditingServiceInterface;
use DH\Auditor\Provider\Service\StorageServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Minimal stub provider for use in compiler pass and integration tests.
 */
final class StubProviderA implements ProviderInterface
{
    private ?Auditor $auditor = null;

    public function setAuditor(Auditor $auditor): static
    {
        $this->auditor = $auditor;

        return $this;
    }

    public function getAuditor(): Auditor
    {
        return $this->auditor ?? new Auditor(new Configuration([]), new EventDispatcher());
    }

    public function getConfiguration(): ConfigurationInterface
    {
        return new class implements ConfigurationInterface {};
    }

    public function isRegistered(): bool
    {
        return null !== $this->auditor;
    }

    public function registerStorageService(StorageServiceInterface $service): static
    {
        return $this;
    }

    public function registerAuditingService(AuditingServiceInterface $service): static
    {
        return $this;
    }

    public function persist(LifecycleEvent $event): void {}

    /**
     * @return StorageServiceInterface[]
     */
    public function getStorageServices(): array
    {
        return [];
    }

    /**
     * @return AuditingServiceInterface[]
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
}
