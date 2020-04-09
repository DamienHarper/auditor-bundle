<?php

namespace DH\DoctrineAuditBundle\Tests;

use ReflectionClass;
use ReflectionMethod;

trait ReflectionTrait
{
    public function reflectMethod(string $class, string $method): ReflectionMethod
    {
        $reflectedClass = new ReflectionClass($class);
        $reflectedMethod = $reflectedClass->getMethod($method);
        $reflectedMethod->setAccessible(true);

        return $reflectedMethod;
    }
}
