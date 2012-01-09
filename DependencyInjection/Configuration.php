<?php

namespace Kitano\PaymentSipsBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Configuration processer.
 * Parses/validates the extension configuration and sets default values.
 *
 * @author Benjamin Dulau <benjamin.dulau@anonymation.com>
 */
class Configuration implements ConfigurationInterface
{
    /**
     * Generates the configuration tree.
     *
     * @return TreeBuilder
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('kitano_payment_sips');

        $this->addConfigSection($rootNode);

        return $treeBuilder;
    }

    /**
     * Parses the kitano_payment_sips config section
     * Example for yaml driver:
     * kitano_payment_sips:
     *     config:
     *         base_url:
     *
     * @param ArrayNodeDefinition $node
     * @return void
     */
    private function addConfigSection(ArrayNodeDefinition $node)
    {
        $node
            ->children()
                ->arrayNode('config')
                    ->children()
                        ->scalarNode('base_url')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('return_url_ok')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('return_url_err')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('notification_url')->defaultValue(null)->end()
                    ->end()
                ->end()
            ->end();
    }
}