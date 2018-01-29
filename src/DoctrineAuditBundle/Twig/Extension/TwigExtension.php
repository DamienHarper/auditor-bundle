<?php

namespace DH\DoctrineAuditBundle\Twig\Extension;

use Symfony\Bridge\Doctrine\RegistryInterface;

class TwigExtension extends \Twig_Extension
{
    protected $doctrine;

    public function getFunctions()
    {
        // Register the function in twig :
        // In your template you can use it as : {{findUser(123)}}
        return [
            new \Twig_SimpleFunction('findUser', [$this, 'findUser']),
            new \Twig_SimpleFunction('class', [$this, 'getClass']),
            new \Twig_SimpleFunction('tablename', [$this, 'getTablename']),
        ];
    }

    public function __construct(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function findUser($id, $repository)
    {
        $em = $this->doctrine->getManager();
        $repo = $em->getRepository($repository);

        return $repo->find($id);
    }

    public function getClass($entity)
    {
        return get_class($entity);
    }

    public function getTablename($entity)
    {
        return $this
            ->doctrine
            ->getManager()
            ->getClassMetadata(get_class($entity))
            ->table['name']
        ;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'twig_extensions';
    }
}
