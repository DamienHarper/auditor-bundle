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
        $treeBuilder = new TreeBuilder('dh_doctrine_audit');
        // Keep compatibility with symfony/config < 4.2
        $rootNode = \method_exists($treeBuilder, 'getRootNode') ? $treeBuilder->getRootNode() : $treeBuilder->root('dh_doctrine_audit');

        $rootNode
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
                            ->booleanNode('enabled')
                                ->defaultTrue()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;

        return $treeBuilder;
    }
}
