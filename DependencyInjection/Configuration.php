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
        $this->addBinSection($rootNode);

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
                        ->scalarNode('merchant_id')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('merchant_country')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('pathfile')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('templatefile')->defaultValue(null)->end()
                        ->scalarNode('default_language')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('default_teplate_file')->defaultValue(null)->end()
                        ->scalarNode('default_currency')->cannotBeEmpty()->defaultValue(978)->end()
                    ->end()
                ->end()
            ->end();
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
    private function addBinSection(ArrayNodeDefinition $node)
    {
        $node
            ->children()
                ->arrayNode('bin')
                    ->children()
                        ->scalarNode('request_bin')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('response_bin')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end();
    }
}