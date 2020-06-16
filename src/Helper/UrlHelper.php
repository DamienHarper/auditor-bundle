<?php

namespace DH\AuditorBundle\Helper;

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
