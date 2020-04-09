<?php

namespace DH\DoctrineAuditBundle\Helper;

final class UrlHelper
{
    private function __construct()
    {
    }

    public static function paramToNamespace(string $entity): string
    {
        return str_replace('-', '\\', $entity);
    }

    public static function namespaceToParam(string $entity): string
    {
        return str_replace('\\', '-', $entity);
    }
}
