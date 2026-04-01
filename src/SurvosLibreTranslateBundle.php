<?php
/** generated from /home/tac/ca/survos/packages/maker-bundle/templates/skeleton/bundle/src/Bundle.tpl.php */

namespace Survos\LibreTranslateBundle;

use Survos\LibreTranslateBundle\Api\FrontendApi;
use Survos\LibreTranslateBundle\Command\IterateCommand;
use Survos\LibreTranslateBundle\Service\LibreTranslateService;
use Survos\LibreTranslateBundle\Service\TranslationClientService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SurvosLibreTranslateBundle extends AbstractBundle
{
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {

        $builder->register(TranslationClientService::class)
            ->setAutoconfigured(true)
            ->setAutowired(true)
            ->setPublic(true)
            ->setArgument('$translationServer', $config['server'])//            ->setArgument('$proxy', $config['proxy'])
        ;

        $builder->register(FrontendApi::class)
            ->setAutowired(true)
            ->setPublic(true);

        $definition = $builder->autowire(LibreTranslateService::class)
            ->setPublic(true);
        $definition->setArgument('$host', $config['host']);
        $definition->setArgument('$apiKey', $config['apikey']);
        $definition->setArgument('$port', $config['port']);

        foreach ([
//                     IterateCommand::class
                 ]
                 as $commandClass) {
            $builder->autowire($commandClass)
                ->setAutoconfigured(true)
                ->addTag('console.command');
        }

    }

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->scalarNode('server')->defaultValue('http://translation-server.survos.com')->end()
            ->scalarNode('host')->defaultValue('http://127.0.0.1')->end()
            ->scalarNode('port')->defaultValue('5000')->end()
            ->scalarNode('apikey')->info("the libretranslate API Key")->defaultValue(null)->end()
//            ->scalarNode('sourceLanguage')->defaultValue(null)->end()
//            ->scalarNode('targetLanguage')->defaultValue(null)->end()
            ->booleanNode('canManage')->defaultTrue()->end()
            ->booleanNode('enableBing')->defaultFalse()->info("if translation is identical, try bing")->end()
//            ->integerNode('min_sunshine')->defaultValue(3)->end()
            ->end();
    }

}
