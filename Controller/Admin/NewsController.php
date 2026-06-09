<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Api\News as NewsApi;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsCreatedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsModifiedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsRemovedEvent;
use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Manager\NewsManager;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Kotaru\SuluUtils\Common\SerializerInterface;
use Kotaru\SuluUtils\Doctrine\DoctrineListRepresentationFactory;
use Kotaru\SuluUtils\Entity\Setting;
use Kotaru\SuluUtils\Traits\AdminControllerTrait;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Component\Rest\ListBuilder\PaginatedRepresentation;
use Sulu\Component\Security\SecuredControllerInterface;
use Sulu\Component\Serializer\ArraySerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class NewsController extends AbstractController implements SecuredControllerInterface
{
    use AdminControllerTrait;

    // serialization groups
    protected static $newsSerializationGroups = [
        'partialMedia',
        'apiNews',
    ];

    public function __construct(
        private readonly DoctrineListRepresentationFactory $doctrineListRepresentationFactory,
        private readonly NewsRepository $newsRepository,
        private readonly EntityManagerInterface $entityManager,
        protected NewsManager $newsManager,
        private MediaManagerInterface $mediaManager,
        protected DomainEventCollectorInterface $domainEventCollector,
    ) {
    }


    public function getAction(int $id, Request $request): Response
    {
        $locale = $this->getLocale($request);
        $request->setRequestFormat('json');
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $news = $this->load($id, $request);
        $apiEntity = $this->generateApiEntity($news);
        $result = $this->getApiEntityData($apiEntity);

        return $this->json(data: $result, context: static::JSON_OPTIONS);
    }

    public function postAction(Request $request): Response
    {
        $locale = $this->getLocale($request);
        $request->setRequestFormat('json');
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $requestData = $request->toArray();


        $news = $this->create($locale);
        $this->save($news);
        $this->flush();

        $this->domainEventCollector->collect(
            new NewsCreatedEvent($news)
        );

        $this->newsManager->mapDataToEntity($requestData, $news);
        if (null === $news->getPublishDate()) {
            $news->setPublishDate(new \DateTimeImmutable('now'));
        }
        $this->save($news);

        $this->flush();

        $apiEntity = $this->generateApiEntity($news);
        $result = $this->getApiEntityData($apiEntity);
        return $this->json(data: $result, context: static::JSON_OPTIONS);
    }

    public function putAction(int $id, Request $request): Response
    {
        $locale = $this->getLocale($request);
        $request->setRequestFormat('json');
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $news = $this->load($id, $request);

        if (!$news) {
            throw new NotFoundHttpException();
        }

        $requestData = $request->toArray();

        $this->newsManager->mapDataToEntity($requestData, $news);

        $this->save($news);

        $this->domainEventCollector->collect(
            new NewsModifiedEvent($news)
        );
        $this->flush();
        $apiEntity = $this->generateApiEntity($news);
        $result = $this->getApiEntityData($apiEntity);

        return $this->json(data: $result, context: static::JSON_OPTIONS);
    }

    public function postTriggerAction(int $id, Request $request): Response
    {
        $action = $request->query->get('action');

        if ('copy' === $action) {
            $locale = $request->getLocale();
            $news = $this->newsRepository->findById($id, $locale);
            if (!$news) {
                throw new NotFoundHttpException();
            }
            $newNews = $this->duplicate($news);
            $this->domainEventCollector->collect(
                new NewsCreatedEvent($newNews)
            );
            $this->flush();

            $request->setRequestFormat('json');
            return $this->json(data: ['id' => $newNews->getId()], context: static::JSON_OPTIONS);
        }
        $locale = $this->getLocale($request);
        $request->setRequestFormat('json');
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $news = $this->load($id, $request);
        if (!$news) {
            throw new NotFoundHttpException();
        }

        match ($action) {
            'enable', 'disable' => \call_user_func(function ($news, $action) {
                    $news->setVisible($action === 'enable' ? true : false);
                    $this->save($news);
                }, $news, $action),
            'set-external', 'unset-external' => \call_user_func(function ($news, $action) {
                    $news->setExternal($action === 'set-external' ? true : false);
                    $this->save($news);
                }, $news, $action),
            'copy-locale' => \call_user_func(function ($news, $request) {
                    $fromLocale = $request->query->get('src') ?? $this->getLocale($request);
                    $toLocales = \explode(',', $request->query->get('dest'));
                    return $this->copyLocale($news, from: $fromLocale, to: $toLocales);
                }, $news, $request),
        };

        $this->domainEventCollector->collect(
            new NewsModifiedEvent($news)
        );
        $this->flush();

        $apiEntity = $this->generateApiEntity($news);
        $result = $this->getApiEntityData($apiEntity);
        return $this->json(data: $result, context: static::JSON_OPTIONS);
    }

    public function deleteAction(int $id, Request $request): Response
    {
        $locale = $this->getLocale($request);
        $request->setRequestFormat('json');
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $news = $this->load($id, $request);

        if ('true' === $request->query->get('deleteLocale')) {
            $this->newsManager->removeTranslation($news, $locale);
            $this->flush();
            return $this->json(null, 204);
        }

        $this->newsManager->remove($news);
        $this->domainEventCollector->collect(
            new NewsRemovedEvent($id, $news->getTitle() ?? '')
        );
        $this->flush();

        return $this->json(null, 204);
    }


    public function getListAction(Request $request): Response
    {
        $locale = $this->getLocale($request);
        $request->setRequestFormat('json');
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $filters = $request->query->getBoolean('showExternal', false) == true ? [] : ['external' => false];
        $listRepresentation = $this->doctrineListRepresentationFactory->createProtectedPaginatedListRepresentation(
            $this->getUser(),
            NewsAdmin::LIST_KEY,
            $filters,
            ['locale' => $locale],
        );

        $items = $this->enhanceItems($listRepresentation->getData(), $locale);

        $list = new PaginatedRepresentation(
            $items,
            $listRepresentation->getRel(),
            $listRepresentation->getPage(),
            $listRepresentation->getLimit(),
            $listRepresentation->getTotal(),
        );

        return $this->json($list->toArray());
    }

    public function getSettings(Request $request): Response
    {
        $settingsRepository = $this->entityManager->getRepository(Setting::class);
        $setting = $settingsRepository->findByKey('news_homepage');
        $content = ['newsHomepage' => null];
        if ($setting) {
            $content = $setting->getContent();
        }
        return $this->json(data: $content, context: static::JSON_OPTIONS);
    }

    public function postSettings(Request $request): Response
    {
        $settingsRepository = $this->entityManager->getRepository(Setting::class);
        $requestData = $request->toArray();
        $setting = $settingsRepository->findByKey('news_homepage');
        if (empty($setting)) {
            $setting = $settingsRepository->create('news_homepage');
        }
        $setting->setContent(['newsHomepage' => $requestData['newsHomepage']]);
        $this->save($setting);
        $this->flush();
        return $this->json(data: $setting->getContent(), context: static::JSON_OPTIONS);
    }
    public function putSettings(Request $request): Response
    {
        $settingsRepository = $this->entityManager->getRepository(Setting::class);
        $requestData = $request->toArray();
        $setting = $settingsRepository->findByKey('news_homepage');
        if (empty($setting)) {
            $setting = $settingsRepository->create('news_homepage');
        }
        $setting->setContent(['newsHomepage' => $requestData['newsHomepage']]);
        $this->save($setting);
        $this->flush();
        $request->setRequestFormat('json');
        return $this->json(data: $setting->getContent(), context: static::JSON_OPTIONS);
    }

    public function getRepository(): NewsRepository
    {
        return $this->newsRepository;
    }

    protected function generateApiEntity(News $entity): NewsApi
    {
        $locale = $entity->hasLocale() ? $entity->getLocale() : $entity->getDefaultLocale();
        $apiObject = new NewsApi($entity, $locale);

        if (!empty($entity->getImage())) {
            $apiObject->setImage($this->mediaManager->getById($entity->getImage()->getId(), $locale));
        }

        return $apiObject;
    }

    protected function getApiEntityData(NewsApi $entity): ?array
    {
        $context = SerializationContext::create()
            ->setGroups(static::$newsSerializationGroups)
            ->setSerializeNull(true);

        $arraySerializer = $this->container->get('array_serializer');

        return $arraySerializer->serialize($entity, $context);
    }

    protected function enhanceItems(array $items, string $locale): array
    {
        $enhancedItems = $this->resolveImageThumbnails($items, $locale);
        $enhancedItems = $this->addGhostLocale($enhancedItems, $locale);
        return $this->addLocales($enhancedItems);
    }

    protected function resolveImageThumbnails(array $items, string $locale): array
    {
        $ids = \array_filter(\array_column($items, 'image'));
        $images = $this->mediaManager->getFormatUrls($ids, $locale);
        foreach ($items as $key => $item) {
            if (
                \array_key_exists('image', $item)
                && $item['image']
                && \array_key_exists($item['image'], $images)
            ) {
                $items[$key]['image'] = $images[$item['image']];
            }
        }
        return $items;
    }
    protected function duplicate(NewsInterface $entity): ?NewsInterface
    {
        $initialLocale = $entity->getLocale();
        $clonedNews = $this->newsManager->fullClone($entity);
        $clonedNews->setExternal(false);
        $clonedNews->setSource(null);
        $clonedNews->setVisible(false);
        foreach ($clonedNews->getTranslations() as $locale => $translation) {
            $clonedNews->setLocale($locale);
            $clonedNews->setTitle($clonedNews->getTitle() . ' (1)');
            $this->newsManager->setRoute($clonedNews);
        }
        $clonedNews->setLocale($initialLocale);
        $this->save($clonedNews);
        return $clonedNews;
    }


    protected function copyLocale(NewsInterface $entity, string $from, array $to): ?NewsInterface
    {
        if (empty($to)) {
            throw new \InvalidArgumentException('Destination url paremeter must be defined');
        }
        $originalLocale = $entity->getLocale();
        foreach ($to as $targetLocale) {
            if ($from === $targetLocale) {
                continue;
            }
            $entity->setLocale($targetLocale);
            $copyTranslation = $entity->getTranslation($from);
            if (null === $entity->getTranslation($targetLocale)) {
                $entity->createTranslation($targetLocale);
            }
            $newTranslation = $entity->getTranslation($targetLocale);
            $newTranslation
                ->setTitle($copyTranslation->getTitle())
                ->setContent($copyTranslation->getContent())
                ->setExtension($copyTranslation->getExtension())
                ->setDescription($copyTranslation->getDescription());

            /** @var MediaInterface */
            $image = $copyTranslation->getImage();
            if (null !== $image) {
                $image = $this->newsManager->getImageCopy($image);
            }
            if (null !== $newTranslation->getImage()) {
                $this->mediaManager->delete($newTranslation->getImage()->getId());
            }
            $newTranslation->setImage($image);

            if (empty($newTranslation->getTitle())) {
                throw new UnprocessableEntityHttpException('Cannot save entity without title.');
            }

            $this->save($entity);
            if (null === $newTranslation->getRoute()) {
                $this->newsManager->generateRoute($entity);
            } else {
                $this->newsManager->updateRoute($entity);
            }
        }
        $entity->setLocale($originalLocale);
        return $entity;
    }


    protected function addLocales(array $items): array
    {
        $ids = \array_filter(\array_column($items, 'id'));
        foreach ($items as $key => $item) {
            $id = $ids[$key];
            $producer = $this->newsManager->get($id);

            $locales = $this->newsRepository->getLocales($producer);
            if (null !== $locales) {
                $items[$key]['availableLocales'] = $locales;
                $items[$key]['contentLocales'] = $locales;
            }
        }
        return $items;
    }

    protected function addGhostLocale(array $items, string $locale): array
    {
        $ids = \array_filter(\array_column($items, 'id'));
        $ghostLocales = $this->newsManager->getGhostLocales($ids, $locale);
        foreach ($items as $key => $item) {
            if (\array_key_exists($item['id'], $ghostLocales)) {
                $items[$key]['ghostLocale'] = $ghostLocales[$item['id']];
            }
        }
        return $items;
    }

    public static function getSubscribedServices(): array
    {
        $services = parent::getSubscribedServices();
        $services['array_serializer'] = ArraySerializerInterface::class;
        return $services;
    }

    public function getSecurityContext(): string
    {
        return NewsAdmin::SECURITY_CONTEXT;
    }
}
