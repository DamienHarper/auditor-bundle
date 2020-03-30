<?php

namespace DH\DoctrineAuditBundle\Helper;

class SchemaHelper
{
    /**
     * Return columns of audit tables.
     *
     * @return array
     */
    public static function getAuditTableColumns(): array
    {
        return [
            'id' => [
                'type' => DoctrineHelper::getDoctrineType('INTEGER'),
                'options' => [
                    'autoincrement' => true,
                    'unsigned' => true,
                ],
            ],
            'type' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'notnull' => true,
                    'length' => 10,
                ],
            ],
            'object_id' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'notnull' => true,
                ],
            ],
            'discriminator' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'transaction_hash' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'notnull' => false,
                    'length' => 40,
                ],
            ],
            'diffs' => [
                'type' => DoctrineHelper::getDoctrineType('JSON'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'blame_id' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                ],
            ],
            'blame_user' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                ],
            ],
            'blame_user_fqdn' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 255,
                ],
            ],
            'blame_user_firewall' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 100,
                ],
            ],
            'ip' => [
                'type' => DoctrineHelper::getDoctrineType('STRING'),
                'options' => [
                    'default' => null,
                    'notnull' => false,
                    'length' => 45,
                ],
            ],
            'created_at' => [
                'type' => DoctrineHelper::getDoctrineType('DATETIME_IMMUTABLE'),
                'options' => [
                    'notnull' => true,
                ],
            ],
        ];
    }

    /**
     * Return indices of an audit table.
     *
     * @param string $tablename
     *
     * @return array
     */
    public static function getAuditTableIndices(string $tablename): array
    {
        return [
            'id' => [
                'type' => 'primary',
            ],
            'type' => [
                'type' => 'index',
                'name' => 'type_'.md5($tablename).'_idx',
            ],
            'object_id' => [
                'type' => 'index',
                'name' => 'object_id_'.md5($tablename).'_idx',
            ],
            'discriminator' => [
                'type' => 'index',
                'name' => 'discriminator_'.md5($tablename).'_idx',
            ],
            'transaction_hash' => [
                'type' => 'index',
                'name' => 'transaction_hash_'.md5($tablename).'_idx',
            ],
            'blame_id' => [
                'type' => 'index',
                'name' => 'blame_id_'.md5($tablename).'_idx',
            ],
            'created_at' => [
                'type' => 'index',
                'name' => 'created_at_'.md5($tablename).'_idx',
            ],
        ];
    }
}
