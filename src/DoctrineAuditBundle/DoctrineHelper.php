<?php

namespace DH\DoctrineAuditBundle;

use Doctrine\ORM\Proxy\Proxy;

class DoctrineHelper
{
    /**
     * Gets the real class name of a class name that could be a proxy.
     *
     * @param string|object $subject
     *
     * @return string
     */
    public static function getRealClass($subject): string
    {
        $class = \is_object($subject) ? \get_class($subject) : $subject;

        if (false === $pos = strrpos($class, '\\' . Proxy::MARKER . '\\')) {
            return $class;
        }

        return substr($class, $pos + Proxy::MARKER_LENGTH + 2);
    }
}