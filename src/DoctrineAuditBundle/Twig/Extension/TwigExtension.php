<?php

namespace DH\DoctrineAuditBundle\Twig\Extension;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    protected $doctrine;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('findUser', [$this, 'findUser']),
            new TwigFunction('class', [$this, 'getClass']),
            new TwigFunction('tablename', [$this, 'getTablename']),
        ];
    }

    public function __construct(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function findUser($id, $repository)
    {
        if (null === $id) {
            return null;
        }

        $em = $this->doctrine->getManager();
        $repo = $em->getRepository($repository);

        return $repo->find($id);
    }

    public function getClass($entity): string
    {
        return \get_class($entity);
    }

    public function getTablename($entity): string
    {
        return $this
            ->doctrine
            ->getManager()
            ->getClassMetadata(\get_class($entity))
            ->getTableName()
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'twig_extensions';
    }
}
