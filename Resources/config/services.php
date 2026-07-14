<?php

// namespace Kotaru\Bundle\SuluNewsBundle;
namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Content\DataProvider\NewsDataProvider;
use Kotaru\Bundle\SuluNewsBundle\Controller\Admin\AuthController;
use Kotaru\Bundle\SuluNewsBundle\Controller\Admin\NewsController;
use Kotaru\Bundle\SuluNewsBundle\Controller\Website\NewsWebsiteController;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsCreatedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsModifiedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsRemovedEvent;
use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsTranslation;
use Kotaru\Bundle\SuluNewsBundle\Event\NewsSearchDeindexEvent;
use Kotaru\Bundle\SuluNewsBundle\Event\NewsSearchIndexEvent;
use Kotaru\Bundle\SuluNewsBundle\Link\NewsLinkProvider;
use Kotaru\Bundle\SuluNewsBundle\Listener\NewsEventListener;
use Kotaru\Bundle\SuluNewsBundle\Listener\NewsSearchListener;
use Kotaru\Bundle\SuluNewsBundle\Manager\NewsManager;
use Kotaru\Bundle\SuluNewsBundle\Preview\NewsPreviewObjectProvider;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsTranslationRepository;
use Kotaru\Bundle\SuluNewsBundle\Routing\NewsRouteDefaultProvider;
use Kotaru\Bundle\SuluNewsBundle\Twig\NewsExtension;
use Kotaru\SuluUtils\Common\MediaCopier;
use Kotaru\SuluUtils\Doctrine\DoctrineListRepresentationFactory;
use Kotaru\SuluUtils\Manager\SuluMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

return static function (ContainerConfigurator $container) {
    $services = $container->services();
    $parameters = $container->parameters();
    $parameters->set('sulu_news.entity.news', News::class);
    $parameters->set('sulu_news.entity.news_translation', NewsTranslation::class);

    $services->alias(
        NewsInterface::class,
        News::class
    );


    $services->set(NewsRepository::class)
        ->public()
        ->tag('doctrine.repository_service')
        ->args([new Reference('doctrine'), '%sulu_news.entity.news%'])
        ->alias('sulu_news.news_repository', NewsRepository::class)
    ;

    $services->set(NewsTranslationRepository::class)
        ->public()
        ->tag('doctrine.repository_service')
        ->args([new Reference('doctrine'), '%sulu_news.entity.news_translation%'])
        ->alias('sulu_news.news_translation_repository', NewsTranslationRepository::class)
    ;


    // Admin
    $services->set(NewsAdmin::class)
        ->args([
            new Reference('sulu_admin.view_builder_factory'),
            new Reference('sulu_activity.activity_list_view_builder_factory'),
            new Reference('sulu_core.webspace.webspace_manager'),
            new Reference('sulu_security.security_checker'),
        ])
        ->tag('sulu.admin')
        ->tag('sulu.context', ['context' => 'admin'])
    ;


    // Manager
    $services->set(NewsManager::class)
        ->args([
            new Reference('doctrine.orm.entity_manager'),
            new Reference('sulu_news.news_repository'),
            new Reference('sulu_route.manager.route_manager'),
            new Reference(SuluMediaManager::class),
            new Reference('sulu_media.media_manager'),
            new Reference('sulu.repository.category'),
            new Reference('sulu_tag.tag_manager'),
            new Reference(MediaCopier::class),
            new Reference('sulu_activity.domain_event_collector'),
            new Reference('event_dispatcher'),
        ])
    ;

    // Controllers
    $services->set(NewsController::class)
        ->public()
        ->args([
            new Reference(DoctrineListRepresentationFactory::class),
            new Reference('sulu_news.news_repository'),
            new Reference('doctrine.orm.entity_manager'),
            new Reference(NewsManager::class),
            new Reference('sulu_media.media_manager'),
        ])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        // ->tag('sulu.context', ['context' => 'admin'])
        ->alias('sulu_news.controller', NewsController::class)
        ->public()
    ;

    $services->set(AuthController::class)
        ->public()
        ->args([
            new Reference('doctrine.orm.entity_manager'),
            new Reference('sulu_security.security_checker'),
            new Reference(UrlGeneratorInterface::class),
            new Reference('sulu_admin.view_registry', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
        ])
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        // ->tag('sulu.context', ['context' => 'admin'])
        ->alias('sulu_news.auth_controller', AuthController::class)
        ->public()
    ;

    $services->set(NewsWebsiteController::class)
        ->public()
        ->tag('controller.service_arguments')
        ->tag('container.service_subscriber')
        ->call('setContainer', [service(\Psr\Container\ContainerInterface::class)])
        ->alias('sulu_news.website_controller', NewsWebsiteController::class)
        ->public()
    ;


    // Smart Content
    $services->set(NewsDataProvider::class)
        ->args([
            new Reference('sulu_news.news_repository'),
            new Reference('sulu_core.array_serializer'),
            new Reference('sulu_media.media_manager'),
            new Reference('request_stack'),
            new Reference('sulu_news.reference_store', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.helper', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            new Reference('sulu_core.webspace.request_analyzer', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            '%sulu_security.permissions%',
        ])
        ->tag('sulu.smart_content.data_provider', ['alias' => NewsInterface::RESOURCE_KEY])
    ;
    // Routing
    $services->set(NewsRouteDefaultProvider::class)
        ->args([
            new Reference('doctrine.orm.entity_manager'),
        ])
        ->tag('sulu_route.defaults_provider')
    ;
    // Link
    $services->set(NewsLinkProvider::class)
        ->args([
            new Reference('sulu_news.news_repository'),
            new Reference('translator'),
        ])
        ->tag('sulu.link.provider', ['alias' => NewsInterface::RESOURCE_KEY])
    ;
    // Preview
    $services->set(NewsPreviewObjectProvider::class)
        ->args([
            new Reference('doctrine.orm.entity_manager'),
            new Reference('sulu_news.news_repository'),
            new Reference(NewsManager::class),
            new Reference('sulu_core.array_serializer'),
        ])
        ->tag('sulu_preview.object_provider', ['provider-key' => NewsInterface::RESOURCE_KEY])
    ;
    // Listeners
    $services->set(NewsEventListener::class)
        ->args([
            new Reference('sulu_website.http_cache.clearer'),
            new Reference('sulu_core.webspace.webspace_manager'),
        ])
        ->tag('kernel.event_listener', ['event'=> NewsCreatedEvent::class, 'method' => 'clearPageCache', 'priority' => 0])
        ->tag('kernel.event_listener', ['event'=> NewsModifiedEvent::class, 'method' => 'clearPageCache', 'priority' => 0])
    ;
    $services->set(NewsSearchListener::class)
        ->args([
            new Reference('massive_search.search_manager'),
        ])
        ->tag('kernel.event_listener', ['event'=> NewsSearchIndexEvent::class, 'method' => 'reindex', 'priority' => 0])
        ->tag('kernel.event_listener', ['event'=> NewsSearchDeindexEvent::class, 'method' => 'deindex', 'priority' => 0])
    ;

    // Twig
    $services->set(NewsExtension::class)
        ->args([
            new Reference(NewsManager::class),
            new Reference('sulu_core.array_serializer'),
            '%default_locale%',
        ])
        ->tag('twig.extension')
    ;

};
