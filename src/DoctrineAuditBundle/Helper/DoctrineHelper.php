<?php

namespace DH\DoctrineAuditBundle\Helper;

use Doctrine\Common\Persistence\Proxy;

class DoctrineHelper
{
    /**
     * Gets the real class name of a class name that could be a proxy.
     *
     * @param object|string $subject
     *
     * @return string
     */
    public static function getRealClass($subject): string
    {
        $class = \is_object($subject) ? \get_class($subject) : $subject;

        if (false === $pos = mb_strrpos($class, '\\'.Proxy::MARKER.'\\')) {
            return $class;
        }

        return mb_substr($class, $pos + Proxy::MARKER_LENGTH + 2);
    }

    /**
     * Given a class name and a proxy namespace returns the proxy name.
     *
     * @param string $className
     * @param string $proxyNamespace
     *
     * @return string
     */
    public static function generateProxyClassName($className, $proxyNamespace): string
    {
        return rtrim($proxyNamespace, '\\').'\\'.Proxy::MARKER.'\\'.ltrim($className, '\\');
    }
}
