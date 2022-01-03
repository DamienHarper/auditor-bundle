<?php

declare(strict_types=1);

namespace DH\AuditorBundle\Helper;

/**
 * @see \DH\AuditorBundle\Tests\Helper\UrlHelperTest
 */
abstract class UrlHelper
{
    public static function paramToNamespace(string $entity): string
    {
        return str_replace('-', '\\', $entity);
    }

    public static function namespaceToParam(string $entity): string
    {
        return str_replace('\\', '-', $entity);
    }
}
