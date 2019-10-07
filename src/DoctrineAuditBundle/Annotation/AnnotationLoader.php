<?php

namespace DH\DoctrineAuditBundle\Annotation;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Persistence\Mapping\ClassMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;

class AnnotationLoader
{
    /**
     * @var AnnotationReader
     */
    private $reader;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->reader = new AnnotationReader();
    }

    public function load(): array
    {
        $configuration = array();

        $metadatas = $this->entityManager->getMetadataFactory()->getAllMetadata();
        foreach ($metadatas as $metadata) {
            $config = $this->getEntityConfiguration($metadata);
            if (null !== $config) {
                $configuration[$metadata->getName()] = $config;
            }
        }

        return $configuration;
    }

    private function getEntityConfiguration(ClassMetadata $metadata): ?array
    {
        $reflection = $metadata->getReflectionClass();

        // Check that we have an Entity annotation
        $annotation = $this->reader->getClassAnnotation($reflection, Entity::class);
        if (null === $annotation) {
            return null;
        }

        // Check that we have an Auditable annotation
        $annotation = $this->reader->getClassAnnotation($reflection, Auditable::class);
        if (null === $annotation) {
            return null;
        }

        $config =  [
            'ignored_columns' => [],
            'enabled' => $annotation->enabled,
        ];

        // Are there any Ignore annotations?
        foreach ($reflection->getProperties() as $property) {
            if ($this->reader->getPropertyAnnotation($property, Ignore::class)) {
                // TODO: $property->getName() might not be the column name
                $config['ignored_columns'][] = $property->getName();
            }
        }

        return $config;
    }
}
