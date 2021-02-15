<?php


namespace DH\DoctrineAuditBundle\Helper;


class UuidHelper
{
    /**
     * @return string
     */
    public static function create(): string
    {
        $guidChars = md5(uniqid(rand(), true));
        return self::format($guidChars);
    }

    /**
     * Форматирование строки UUID
     * @param $guidChars
     * @return string
     */
    public static function format($guidChars): string
    {
        return implode('-', [
            substr($guidChars, 0, 8),
            substr($guidChars, 8, 4),
            substr($guidChars, 12, 4),
            substr($guidChars, 16, 4),
            substr($guidChars, 20, 12),
        ]);
    }
}