<?php

namespace DH\DoctrineAuditBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritdoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();

        $treeBuilder->root('dh_doctrine_audit')
            ->children()
                ->scalarNode('table_prefix')
                    ->defaultValue('')
                ->end()
                ->scalarNode('table_suffix')
                    ->defaultValue('_audit')
                ->end()

                ->arrayNode('ignored_columns')
                    ->canBeUnset()
                    ->prototype('scalar')->end()
                ->end()

                ->arrayNode('entities')
                    ->canBeUnset()
                    ->prototype('array')
                        ->children()
                            ->arrayNode('ignored_columns')
                                ->canBeUnset()
                                ->prototype('scalar')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()

                ->booleanNode('show_user_firewall')
                    ->canBeUnset()
                    ->defaultFalse()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
