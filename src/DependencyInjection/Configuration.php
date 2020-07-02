<?php

namespace DH\AuditorBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree builder.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dh_auditor');

        $this->getRootNode($treeBuilder, 'dh_auditor')
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                ->end()
                ->scalarNode('timezone')
                    ->defaultValue('UTC')
                ->end()
                ->append($this->getProvidersNode())
            ->end()
        ;

        return $treeBuilder;
    }

    /**
     * Proxy to get root node for Symfony < 4.2.
     */
    protected function getRootNode(TreeBuilder $treeBuilder, string $name): ArrayNodeDefinition
    {
        if (method_exists($treeBuilder, 'getRootNode')) {
            return $treeBuilder->getRootNode();
        }

        return $treeBuilder->root($name);
    }

    private function getProvidersNode(): ArrayNodeDefinition
    {
        $treeBuilder = new TreeBuilder('providers');

        return $this->getRootNode($treeBuilder, 'providers')
            ->requiresAtLeastOneElement()
            ->useAttributeAsKey('name')
            ->variablePrototype()
                ->validate()
                    ->ifEmpty()
                    ->thenInvalid('Invalid provider configuration %s')
                ->end()
            ->end()

            ->validate()
                ->always()
                ->then(function ($v) {
                    if (!\array_key_exists('doctrine', $v)) {
                        $v['doctrine'] = [];
                    }

                    // "table_prefix" is empty by default.
                    if (!\array_key_exists('table_prefix', $v['doctrine']) || !\is_string($v['doctrine']['table_prefix'])) {
                        $v['doctrine']['table_prefix'] = '';
                    }

                    // "table_suffix" is "_audit" by default.
                    if (!\array_key_exists('table_suffix', $v['doctrine']) || !\is_string($v['doctrine']['table_suffix'])) {
                        $v['doctrine']['table_suffix'] = '_audit';
                    }

                    // "entities" are "enabled" by default.
                    if (\array_key_exists('entities', $v['doctrine']) && \is_array($v['doctrine']['entities'])) {
                        foreach ($v['doctrine']['entities'] as $entity => $options) {
                            if (null === $options || !\array_key_exists('enabled', $options)) {
                                $v['doctrine']['entities'][$entity]['enabled'] = true;
                            }
                        }
                    }

                    // "doctrine.orm.default_entity_manager" is the default "storage_services"
                    if (\array_key_exists('storage_services', $v['doctrine']) && \is_string($v['doctrine']['storage_services'])) {
                        $v['doctrine']['storage_services'] = [$v['doctrine']['storage_services']];
                    } elseif (!\array_key_exists('storage_services', $v['doctrine']) || !\is_array($v['doctrine']['storage_services'])) {
                        $v['doctrine']['storage_services'] = ['doctrine.orm.default_entity_manager'];
                    }

                    // "doctrine.orm.default_entity_manager" is the default "auditing_services"
                    if (\array_key_exists('auditing_services', $v['doctrine']) && \is_string($v['doctrine']['auditing_services'])) {
                        $v['doctrine']['auditing_services'] = [$v['doctrine']['auditing_services']];
                    } elseif (!\array_key_exists('auditing_services', $v['doctrine']) || !\is_array($v['doctrine']['auditing_services'])) {
                        $v['doctrine']['auditing_services'] = ['doctrine.orm.default_entity_manager'];
                    }

                    // "viewer" is enabled by default
                    if (!\array_key_exists('viewer', $v['doctrine']) || !\is_bool($v['doctrine']['viewer'])) {
                        $v['doctrine']['viewer'] = true;
                    }

                    // "storage_mapper" is null by default
                    if (!\array_key_exists('storage_mapper', $v['doctrine']) || !\is_string($v['doctrine']['storage_mapper'])) {
                        $v['doctrine']['storage_mapper'] = null;
                    }

                    // "role_checker" is null by default
                    if (!\array_key_exists('role_checker', $v['doctrine']) || !\is_string($v['doctrine']['role_checker'])) {
                        $v['doctrine']['role_checker'] = 'dh_auditor.role_checker';
                    }

                    // "user_provider" is null by default
                    if (!\array_key_exists('user_provider', $v['doctrine']) || !\is_string($v['doctrine']['user_provider'])) {
                        $v['doctrine']['user_provider'] = 'dh_auditor.user_provider';
                    }

                    // "security_provider" is null by default
                    if (!\array_key_exists('security_provider', $v['doctrine']) || !\is_string($v['doctrine']['security_provider'])) {
                        $v['doctrine']['security_provider'] = 'dh_auditor.security_provider';
                    }

                    return $v;
                })
            ->end()
        ;
    }
}
