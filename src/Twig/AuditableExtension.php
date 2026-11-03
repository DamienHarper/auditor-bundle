<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Twig;

use DH\Auditor\Provider\Doctrine\Persistence\Reader\Reader;
use Twig\Attribute\AsTwigFunction;

final readonly class AuditableExtension
{
    public function __construct(
        private Reader $reader,
    ) {}

    #[AsTwigFunction('dh_auditor_is_auditable')]
    public function isAuditable(string $entityClass): bool
    {
        return $this->reader->getProvider()->isAuditable($entityClass);
    }
}
