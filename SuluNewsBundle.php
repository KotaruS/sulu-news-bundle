<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsTranslation;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsTranslationRepository;
use Sulu\Bundle\PersistenceBundle\DependencyInjection\PersistenceExtensionTrait;
use Sulu\Bundle\PersistenceBundle\PersistenceBundleTrait;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

class SuluNewsBundle extends AbstractBundle
{
    use PersistenceBundleTrait, PersistenceExtensionTrait;

    public const SYSTEM_COLLECTION_ROOT = 'sulu_news';


    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $this->buildPersistence(
            [
                NewsInterface::class => 'sulu.model.news.class',
            ],
            $container
        );
    }

    public function prependExtension(ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig(
                'doctrine',
                [
                    'orm' => [
                        'mappings' => [
                            'SuluNewsBundle' => [
                                'type' => 'xml',
                                'dir' => __DIR__ . '/Resources/config/doctrine',
                                'prefix' => 'Kotaru\Bundle\SuluNewsBundle\Entity',
                                'alias' => 'SuluNewsBundle',
                            ],
                        ],
                    ],
                ]
            );
        }
        if ($container->hasExtension('jms_serializer')) {
            $container->prependExtensionConfig(
                'jms_serializer',
                [
                    'metadata' => [
                        'directories' => [
                            [
                                'name' => 'sulu_news',
                                'path' => __DIR__ . '/Resources/config/serializer',
                                'namespace_prefix' => 'Kotaru\Bundle\SuluNewsBundle',
                            ],
                        ],
                    ],
                ]
            );
        }

        if ($container->hasExtension('sulu_admin')) {
            $configurator->extension(
                'sulu_admin',
                [
                    'lists' => [
                        'directories' => [
                            __DIR__ . '/Resources/config/lists',
                        ],
                    ],
                    'forms' => [
                        'directories' => [
                            __DIR__ . '/Resources/config/forms',
                        ],
                    ],
                    'resources' => [
                        NewsInterface::RESOURCE_KEY => [
                            'routes' => [
                                'list' => 'sulu_news.get_news_list',
                                'detail' => 'sulu_news.get_news'
                            ],
                        ],
                        NewsInterface::RESOURCE_KEY . '_settings' => [
                            'routes' => [
                                'detail' => 'sulu_news.get_news_settings'
                            ],
                        ],
                    ],
                ],
                true,
            );
        }
        if ($container->hasExtension('sulu_media')) {
            $configurator->extension(
                'sulu_media',
                [
                    'system_collections' => [
                        self::SYSTEM_COLLECTION_ROOT => [
                            'meta_title' => ['en' => 'Sulu news', 'cs' => 'Sulu články'],
                            'collections' => [
                                'headers' => [
                                    'meta_title' => ['en' => 'Header Images', 'cs' => 'Úvodní obrázky'],
                                ],
                            ],
                        ],
                    ],
                ],
                true,
            );
        }

        if ($container->hasExtension('sulu_route')) {
            $configurator->extension(
                'sulu_route',
                [
                    'mappings' => [
                        News::class => [
                            'generator' => 'schema',
                            'options' => [
                                'route_schema' => "/{translator.trans('sulu_news.news_route')}/{object.getTitle()}",
                            ],
                            'resource_key' => NewsInterface::RESOURCE_KEY
                        ],
                    ],
                ],
                true
            );
        }
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig(
                'framework',
                [
                    'translator' => [
                        'paths' => [
                            __DIR__ . '/Resources/translations',
                        ],
                    ],
                ]
            );

        }
          if ($container->hasExtension('sulu_search')) {
            $container->prependExtensionConfig(
                'sulu_search',
                [
                    'indexes' => [
                        NewsInterface::RESOURCE_KEY . '_published' => [
                            'name' => 'sulu_news.search.index.news',
                            'icon' => 'su-news',
                            'view' => [
                                'name' => NewsAdmin::EDIT_FORM_VIEW,
                                'result_to_view' => [
                                    'properties/news_id' => 'id',
                                    'locale' => 'locale',
                                ],
                            ],
                            'security_context' => NewsAdmin::SECURITY_CONTEXT,
                        ]
                    ],
                    'website' => [
                        'indexes' => [
                             NewsInterface::RESOURCE_KEY . '_published',
                        ],
                    ]
                ]
            );
        }
    }


    public function configure(DefinitionConfigurator $definition): void
    {

        $root = $definition->rootNode();

        $root->children()
            ->arrayNode('objects')->addDefaultsIfNotSet()
            ->children()
            ->arrayNode('news')->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('model')->defaultValue(News::class)->end()
            ->scalarNode('repository')->defaultValue(NewsRepository::class)->end()
            ->end()
            ->end()
            ->arrayNode('news_translation')->addDefaultsIfNotSet()
            ->children()
            ->scalarNode('model')->defaultValue(NewsTranslation::class)->end()
            ->scalarNode('repository')->defaultValue(NewsTranslationRepository::class)->end()
            ->end()
            ->end()
            ->end()
            ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/Resources/config/services.php');


        if ($config['objects']) {
            $this->configurePersistence($config['objects'], $builder);
        }
    }

    public function getPath(): string
    {
        if (!isset($this->path)) {
            $reflected = new \ReflectionObject($this);
            // assume the modern directory structure by default
            $this->path = \dirname($reflected->getFileName());
        }

        return $this->path;
    }
}
