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

        $news = $this->newsManager->create($locale, $requestData);

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

        $news = $this->newsManager->update($news, $requestData);

        $apiEntity = $this->generateApiEntity($news);
        $result = $this->getApiEntityData($apiEntity);

        return $this->json(data: $result, context: static::JSON_OPTIONS);
    }

    public function postTriggerAction(int $id, Request $request): Response
    {
        $action = $request->query->get('action');
        $request->setRequestFormat('json');

        if ('copy' === $action) {
            $locale = $request->getLocale();
            $news = $this->newsRepository->findById($id, $locale);
            if (!$news) {
                throw new NotFoundHttpException();
            }
            $newNews = $this->newsManager->copy($news);

            return $this->json(data: ['id' => $newNews->getId()], context: static::JSON_OPTIONS);
        }
        $locale = $this->getLocale($request);
        if (null === $locale) {
            throw new BadRequestException("Missing locale query parameter.", 400);
        }
        $news = $this->load($id, $request);
        if (!$news) {
            throw new NotFoundHttpException();
        }

        match ($action) {
            'enable', 'disable' => \call_user_func(function ($news, $action) {
                    if ($action === 'enable') {
                        $this->newsManager->enable($news);
                    } else {
                        $this->newsManager->disable($news);
                    }
                }, $news, $action),
            'set-external', 'unset-external' => \call_user_func(function ($news, $action) {
                    $this->newsManager->update($news, ['external' => $action === 'set-external']);
                }, $news, $action),
            'copy-locale' => \call_user_func(function ($news, $request) {
                    $fromLocale = $request->query->get('src') ?? $this->getLocale($request);
                    $toLocales = \explode(',', $request->query->get('dest'));
                    $news = $this->newsManager->copyLocale($news, from: $fromLocale, to: $toLocales);
                }, $news, $request),
        };

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

            return $this->json(null, 204);
        }

        $this->newsManager->remove($news);

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
