<?php

namespace DH\DoctrineAuditBundle\Helper;

use Doctrine\Persistence\Proxy;

class DoctrineHelper
{
    /**
     * Gets the real class name of a class name that could be a proxy.
     *
     * @param object|string $class
     *
     * @return string
     *
     * credits
     * https://github.com/api-platform/core/blob/master/src/Util/ClassInfoTrait.php
     */
    public static function getRealClassName($class): string
    {
        $class = \is_object($class) ? \get_class($class) : $class;

        // __CG__: Doctrine Common Marker for Proxy (ODM < 2.0 and ORM < 3.0)
        // __PM__: Ocramius Proxy Manager (ODM >= 2.0)
        $positionCg = mb_strrpos($class, '\\__CG__\\');
        $positionPm = mb_strrpos($class, '\\__PM__\\');
        if ((false === $positionCg) &&
            (false === $positionPm)) {
            return $class;
        }
        if (false !== $positionCg) {
            return mb_substr($class, $positionCg + 8);
        }
        $className = ltrim($class, '\\');

        return mb_substr(
            $className,
            8 + $positionPm,
            mb_strrpos($className, '\\') - ($positionPm + 8)
        );
    }
}
