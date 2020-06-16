<?php

namespace DH\AuditorBundle\Twig\Extension;

use Doctrine\Persistence\ManagerRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TwigExtension extends AbstractExtension
{
    /**
     * @var ManagerRegistry
     */
    protected $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', 'json_decode'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'twig_extensions';
    }
}
