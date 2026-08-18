<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsCreatedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsModifiedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsRemovedEvent;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Event\NewsSearchDeindexEvent;
use Kotaru\Bundle\SuluNewsBundle\Event\NewsSearchIndexEvent;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Kotaru\SuluUtils\Common\MediaCopier;
use Kotaru\SuluUtils\Manager\SuluMediaManager;
use Kotaru\SuluUtils\Traits\CategorySetterTrait;
use Kotaru\SuluUtils\Traits\DataSetterTrait;
use Kotaru\SuluUtils\Traits\TagSetterTrait;
use Sulu\Bundle\ActivityBundle\Application\Collector\DomainEventCollectorInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryRepositoryInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Exception\MediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\RouteBundle\Manager\RouteManagerInterface;
use Sulu\Bundle\RouteBundle\Model\RoutableInterface;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class NewsManager
{
    use CategorySetterTrait, TagSetterTrait, DataSetterTrait;

    public function __construct(
        protected EntityManagerInterface $entityManager,
        protected NewsRepository $newsRepository,
        protected RouteManagerInterface $routeManager,
        protected SuluMediaManager $customMediaManager,
        protected MediaManagerInterface $mediaManager,
        protected CategoryRepositoryInterface $categoryRepository,
        protected TagManagerInterface $tagManager,
        protected MediaCopier $mediaCopier,
        protected DomainEventCollectorInterface $domainEventCollector,
        protected EventDispatcherInterface $eventDispatcher,
    ) {
    }

    /**
     * @param array $data
     * @param NewsInterface $entity
     */
    public function mapDataToEntity(array $data, $entity): ?NewsInterface
    {
        // when image is null we transform it a little
        if (\array_key_exists('image',$data) && !isset($data['image'])) {
            $data['image'] = ['id' => null];
        }

        $this
            ->setWithData($data, '[title]', fn($v) => $entity->setTitle($v))
            ->setWithData($data, '[ext][seo]', fn($v) => $entity->setExtension($this->getMergedExtension($entity, 'seo', $v)))
            ->setWithData($data, '[ext][excerpt]', fn($v) => $entity->setExtension($this->getMergedExtension($entity, 'excerpt', $v)))
            ->setWithData($data, '[description]', fn($v) => $entity->setDescription($v))
            ->setWithData($data, '[content]', fn($v) => $entity->setContent($v))
            ->setWithData($data, '[publishDate]', fn($v) => $entity->setPublishDate($this->getDateTime($v)))
            ->setWithData($data, '[route]', fn($v) => $this->setRoute($entity, $v))
            ->setWithData($data, '[categories]', fn($v) => $this->setCategories($entity, $v))
            ->setWithData($data, '[tags]', fn($v) => $this->setTags($entity, $v))
            ->setWithData($data, '[external]', fn($v) => $entity->setExternal($v ?? false))
            ->setWithData($data, '[external_url]', fn($v) => $entity->setSource($v))
            ->setWithData($data, '[visible]', fn($v) => $entity->setVisible($v ?? false), )
            ->setWithData($data, '[image][id]', fn($v) => $entity->setImage($this->getImage($v)));

        return $entity;
    }

    private function getDateTime(null|string|\DateTimeInterface $datetime): ?\DateTimeInterface
    {
        if (null === $datetime) {
            return null;
        }
        if ($datetime instanceof \DateTimeInterface) {
            return $datetime;
        }
        return \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s', $datetime);
    }


    public function getGhostLocales(array $ids, string $locale): array
    {
        $news = $this->getbyIds($ids, $locale);
        if (!$news) {
            return [];
        }
        $ghostLocales = [];
        foreach ($news as $oneNews) {
            if (null === $oneNews->getTranslation($locale)) {
                $defaultLocale = $oneNews->getDefaultLocale();
                if (null === $defaultLocale) {
                    throw new EntityNotFoundException(NewsInterface::class . ' Entity must have defaultLocale.');
                }
                $ghostLocales[$oneNews->getId()] = $defaultLocale;
            }
        }
        return $ghostLocales;
    }


    public function get(int $id, string $locale = ''): ?NewsInterface
    {
        if ('' === $locale) {
            return $this->newsRepository->find($id);
        }
        return $this->newsRepository->findById($id, $locale);
    }

    /**
     * Returns array of related news
     * @param int $id id of the news
     * @param string $locale
     * @return NewsInterface[]|[]
     */
    public function getRelated(int $id, string $locale = 'cs', int $limit = 3): array
    {
        $main = $this->newsRepository->findById($id, $locale);
        $filters = [
            'date' => $main->getPublishDate() ?? new \DateTimeImmutable('now'),
            'exclude' => [$id],
        ];
        $categories = \array_reduce($main->getCategories()->toArray(), fn($ctgs, $category) => [...$ctgs, $category->getKey()], []);
        if (!empty($categories)) {
            $filters['categories'] = $categories;
        }

        return $this->newsRepository->findRelated(
            $locale,
            $limit,
            $filters
        );

        return [];
    }

    /**
     * @return NewsInterface[]|null
     */
    public function getbyIds(array $ids, string $locale): ?array
    {
        return $this->newsRepository->findbyIds($ids, $locale);
    }

    public function getImageCopy(MediaInterface $image): ?MediaInterface
    {
        $media = $this->mediaCopier->getCopy($image);
        $this->entityManager->flush();
        return $media;
    }

    // CRUD
    public function create(string $locale, array $data): NewsInterface
    {
        $news = $this->newsRepository->create($locale);
        $this->save($news);

        $this->domainEventCollector->collect(
            new NewsCreatedEvent($news)
        );

        $this->mapDataToEntity($data, $news);
        if (null === $news->getPublishDate()) {
            $news->setPublishDate(new \DateTimeImmutable('now'));
        }
        $this->save($news);
        $this->eventDispatcher->dispatch(new NewsSearchIndexEvent($news));

        return $news;
    }

    public function update(NewsInterface $news, array $data): NewsInterface
    {
        $this->eventDispatcher->dispatch(new NewsSearchDeindexEvent($news));

        $news=$this->modify($news, $data);

        $this->eventDispatcher->dispatch(new NewsSearchIndexEvent($news));

        return $news;
    }
    public function modify(NewsInterface $news, array $data): NewsInterface
    {
        $this->mapDataToEntity($data, $news);

        $this->save($news, false);

        $this->domainEventCollector->collect(
            new NewsModifiedEvent($news)
        );
        $this->entityManager->flush();

        return $news;
    }
    public function copy(NewsInterface $news): NewsInterface
    {
        $initialLocale = $news->getLocale();
        $clonedNews = $this->fullClone($news);
        $clonedNews->setExternal(false);
        $clonedNews->setSource(null);
        $clonedNews->setVisible(false);
        foreach ($clonedNews->getTranslations() as $locale => $translation) {
            $clonedNews->setLocale($locale);
            $clonedNews->setTitle($clonedNews->getTitle() . ' (1)');
            $this->setRoute($clonedNews);
        }
        $clonedNews->setLocale($initialLocale);
        $this->save($clonedNews, false);

        $this->domainEventCollector->collect(
            new NewsCreatedEvent($clonedNews)
        );
        $this->entityManager->flush();
        $this->eventDispatcher->dispatch(new NewsSearchIndexEvent($clonedNews));

        return $clonedNews;
    }
    public function copyLocale(NewsInterface $news, string $from, array $to): ?NewsInterface
    {
        if (empty($to)) {
            throw new \InvalidArgumentException('Destination url paremeter must be defined');
        }
        $this->eventDispatcher->dispatch(new NewsSearchDeindexEvent($news));
        $originalLocale = $news->getLocale();
        foreach ($to as $targetLocale) {
            if ($from === $targetLocale) {
                continue;
            }
            $news->setLocale($targetLocale);
            $copyTranslation = $news->getTranslation($from);
            if (null === $news->getTranslation($targetLocale)) {
                $news->createTranslation($targetLocale);
            }
            $newTranslation = $news->getTranslation($targetLocale);
            $newTranslation
                ->setTitle($copyTranslation->getTitle())
                ->setContent($copyTranslation->getContent())
                ->setExtension($copyTranslation->getExtension())
                ->setDescription($copyTranslation->getDescription());

            /** @var MediaInterface */
            $image = $copyTranslation->getImage();
            if (null !== $image) {
                $image = $this->getImageCopy($image);
            }
            if (null !== $newTranslation->getImage()) {
                $this->mediaManager->delete($newTranslation->getImage()->getId());
            }
            $newTranslation->setImage($image);

            if (empty($newTranslation->getTitle())) {
                throw new UnprocessableEntityHttpException('Cannot save entity without title.');
            }

            $this->save($news, false);
            if (null === $newTranslation->getRoute()) {
                $this->generateRoute($news);
            } else {
                $this->updateRoute($news);
            }
        }
        $news->setLocale($originalLocale);
        $this->domainEventCollector->collect(
            new NewsModifiedEvent($news)
        );
        $this->save($news);
        $this->eventDispatcher->dispatch(new NewsSearchIndexEvent($news));

        return $news;
    }

    public function enable(NewsInterface $news): NewsInterface
    {
        $this->eventDispatcher->dispatch(new NewsSearchDeindexEvent($news));
        $this->modify($news, ['visible'=> true]);
        $this->eventDispatcher->dispatch(new NewsSearchIndexEvent($news));
        return $news;
    }
    public function disable(NewsInterface $news): NewsInterface
    {
        $this->modify($news, ['visible'=> false]);
        $this->eventDispatcher->dispatch(new NewsSearchDeindexEvent($news));
        return $news;
    }

    public function save(NewsInterface $news, bool $flush = true): void
    {
        $this->newsRepository->save($news);
        if (true === $flush) {
            $this->entityManager->flush();
        }
    }

    public function persist(NewsInterface $news): void
    {
        $this->newsRepository->save($news);
    }
    public function remove(NewsInterface $news, bool $flush = true): void
    {
        $this->domainEventCollector->collect(
            new NewsRemovedEvent($news)
        );
        $this->domainEventCollector->dispatch();
        $this->eventDispatcher->dispatch(new NewsSearchDeindexEvent($news));

        foreach ($news->getTranslations() as $translation) {
            $image = $translation->getImage();
            $translation->setImage(null);
            $this->entityManager->persist($translation);
            if (null !== $image) {
                $this->customMediaManager->delete($image);
            }
        }
        $this->newsRepository->remove($news->getId());
        if (true === $flush) {
            $this->entityManager->flush();
        }

    }

    public function removeTranslation(NewsInterface $news, $locale): bool
    {
        $this->eventDispatcher->dispatch(new NewsSearchDeindexEvent($news));
        $translations = $news->getTranslations();
        $translation = $news->getTranslation($locale);
        if (0 === count(\array_filter($translations, fn($lang) => $lang !== $locale, \ARRAY_FILTER_USE_KEY))) {
            throw new \InvalidArgumentException(\sprintf('Cannot delete only translation for locale "%s" found for news %s.', $locale, $news->getTitle()));
        }

        $removed = $news->removeTranslation($translation);
        if (false === $removed) {
            throw new \InvalidArgumentException(\sprintf('No translation for locale "%s" found for news %s.', $locale, $news->getTitle()));
        }
        // $route = $translation->getRoute();
        // if (null !== $route) {
        //   $this->entityManager->remove($route);
        // }
        $image = $translation->getImage();
        if (null !== $image) {
            $this->customMediaManager->delete($image);
        }
        if ($locale === $news->getDefaultLocale()) {
            $news->setDefaultLocale($this->newsRepository->getLocales($news)[0]);
        }
        $this->domainEventCollector->collect(
            new NewsModifiedEvent($news)
        );
        $this->save($news);
        $this->eventDispatcher->dispatch(new NewsSearchIndexEvent($news));

        return $removed;
    }

    // public function copyPure(NewsInterface $source, NewsInterface $target): NewsInterface
    // {
    //     $originalLocaleS = $source->hasLocale() ? $source->getLocale() : null;
    //     $originalLocaleT = $target->hasLocale() ? $target->getLocale() : null;
    //     $locales = $this->newsRepository->getLocales($source);
    //     $target
    //         ->setDefaultLocale($source->getDefaultLocale())
    //         ->setVisible($source->isVisible())
    //         ->setExternal($source->isExternal())
    //         ->setSource($source->getSource())
    //         ->setPublishDate($source->getPublishDate())
    //     ;

    //     foreach ($locales as $locale) {
    //         $source->setLocale($locale);
    //         $target->setLocale($locale);

    //         $target
    //             ->setTitle($source->getTitle())
    //             ->setExtension($source->getExtension())
    //             ->setDescription($source->getDescription())
    //             ->setContent($source->getContent())
    //         ;
    //     }
    //     if (null !== $originalLocaleS) {
    //         $source->setLocale($originalLocaleS);
    //     }
    //     if (null !== $originalLocaleT) {
    //         $target->setLocale($originalLocaleT);
    //     }
    //     $this->persist($source);
    //     $this->persist($target);
    //     return $target;
    // }
    // public function copyMutate(NewsInterface $source, NewsInterface $target, bool $override = false): NewsInterface
    // {
    //     $originalLocaleS = $source->hasLocale() ? $source->getLocale() : null;
    //     $originalLocaleT = $target->hasLocale() ? $target->getLocale() : null;
    //     $locales = $this->newsRepository->getLocales($source);

    //     $this->setCategories(
    //         $target,
    //         \array_map(fn($category) => $category->getId(), $source->getCategories()->toArray())
    //     );
    //     $this->setTags(
    //         $target,
    //         \array_map(fn($tag) => $tag->getId(), $source->getTags()->toArray())
    //     );

    //     foreach ($locales as $locale) {
    //         $source->setLocale($locale);
    //         $target->setLocale($locale);

    //         if (null !== $source->getImage() || $override) {
    //             $img = $source->getImage();
    //             $targetImage = $target->getImage();
    //             if (null !== $targetImage) {
    //                 $this->customMediaManager->delete($targetImage);
    //             }
    //             $target->setImage($img);
    //         }

    //         $oldRoute = $source->getRoute();
    //         if (null === $oldRoute && false === $override) {
    //             continue;
    //         }
    //         $newRoute = $this->setRoute($target, $oldRoute?->getPath());

    //         if ($target->getTitle() !== $source->getTitle()) {
    //             // flip the routes
    //             $target->setRoute($oldRoute);
    //             $source->setRoute($newRoute);
    //             $newRoute?->setEntityId($source->getId());
    //             $oldRoute?->setEntityId($target->getId());
    //         }
    //     }
    //     if (null !== $originalLocaleS) {
    //         $source->setLocale($originalLocaleS);
    //     }
    //     if (null !== $originalLocaleT) {
    //         $target->setLocale($originalLocaleT);
    //     }
    //     $this->persist($source);
    //     $this->persist($target);
    //     return $target;
    // }

    // public function copy(NewsInterface $source, NewsInterface $target): NewsInterface
    // {
    //     $this->copyPure($source, $target);
    //     $this->copyMutate($source, $target);
    //     return $target;
    // }
    public function fullClone(NewsInterface $news, $createRoutes = false): NewsInterface
    {
        $clonedNews = clone $news;
        $this->save($clonedNews);

        $originalLocale = $news->hasLocale() ? $news->getLocale() : null;

        foreach ($clonedNews->getTranslations() as $locale => $translation) {
            $clonedNews->setLocale($locale);

            if (null !== $clonedNews->getImage()) {
                $img = $clonedNews->getImage();
                $clonedNews->setImage(null === $img ? null : $this->getImageCopy($img));
            }

            if (true === $createRoutes) {
                $this->setRoute($clonedNews);
            }
        }

        if (null !== $originalLocale) {
            $clonedNews->setLocale($originalLocale);
        }
        return $clonedNews;
    }


    public function hydrateWithData(NewsInterface $entity, array $data): NewsInterface
    {
        if (isset($data['ext'])) {
            $data['extension'] = $data['ext'];
        }
        if (isset($data['route'])) {
            $data['path'] = $data['route'];
        }

        $this
            ->setWithData($data, '[title]', fn($v) => $entity->setTitle($v))
            ->setWithData($data, '[extension][seo]', fn($v) => $entity->setExtension($this->getMergedExtension($entity, 'seo', $v)))
            ->setWithData($data, '[extension][excerpt]', fn($v) => $entity->setExtension($this->getMergedExtension($entity, 'excerpt', $v)))
            ->setWithData($data, '[description]', fn($v) => $entity->setDescription($v))
            ->setWithData($data, '[content]', fn($v) => $entity->setContent($v))
            ->setWithData($data, '[publishDate]', fn($v) => $entity->setPublishDate($this->getDateTime($v)))
            ->setWithData($data, '[path]', fn($v) => $this->hydratePath($entity, $v))
            ->setWithData($data, '[categories]', fn($v) => $this->setCategories($entity, $v))
            ->setWithData($data, '[tags]', fn($v) => $this->setTags($entity, $v))
            ->setWithData($data, '[visible]', fn($v) => $entity->setVisible($v ?? false))
            ->setWithData($data, '[image][id]', fn($v) => $entity->setImage($this->getImage($v)));

        return $entity;
    }
    public function hydratePath(NewsInterface $entity, string $path = null): ?RouteInterface
    {
        $routeEntity = $entity->getRoute();
        if (null === $routeEntity) {
            return null;
        }
        $routeEntity->setPath($path);
        return $routeEntity;
    }


    public function updateRoute(RoutableInterface $entity, $path = null)
    {
        $routeEntity = $entity->getRoute();
        if (null !== $routeEntity && $path !== $entity->getRoute()->getPath()) {
            return $this->routeManager->update($entity, $path);
        }
        return $routeEntity;
    }
    public function setRoute(RoutableInterface $entity, $path = null): ?RouteInterface
    {
        $routeEntity = $entity->getRoute();
        if (null === $routeEntity) {
            return $this->routeManager->create($entity, $path);
        }
        return $this->routeManager->update($entity, $path);
    }

    public function generateRoute(RoutableInterface $entity, $path = null)
    {
        return $this->routeManager->create($entity, $path);
    }


    protected function setImage($entity, $mediaId, $setter = null)
    {
        $mediaEntity = $this->getImage($mediaId);

        if (!$setter) {
            $entity->setImage($mediaEntity);
        }
        $setter($mediaEntity);
        return $entity;
    }

    protected function getImage(?int $mediaId): ?MediaInterface
    {
        if (!$mediaId) {
            return null;
        }
        $mediaEntity = $this->mediaManager->getEntityById($mediaId);

        if (!$mediaEntity instanceof MediaInterface) {
            throw new MediaNotFoundException((string) $mediaId);
        }

        return $mediaEntity;
    }

    protected function getMergedExtension($entity, $key, $value): array
    {
        $ext = $entity->getExtension();
        $ext[$key] = $value;
        return $ext;
    }
}
