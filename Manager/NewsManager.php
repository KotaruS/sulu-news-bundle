<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Manager;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Kotaru\SuluUtils\Common\MediaCopier;
use Kotaru\SuluUtils\Manager\SuluMediaManager;
use Kotaru\SuluUtils\Traits\CategorySetterTrait;
use Kotaru\SuluUtils\Traits\DataSetterTrait;
use Kotaru\SuluUtils\Traits\TagSetterTrait;
use Sulu\Bundle\CategoryBundle\Entity\CategoryRepositoryInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\MediaBundle\Media\Exception\MediaNotFoundException;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\RouteBundle\Manager\RouteManagerInterface;
use Sulu\Bundle\RouteBundle\Model\RoutableInterface;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Bundle\TagBundle\Tag\TagManagerInterface;

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
    ) {
    }

    /**
     * @param array $data
     * @param NewsInterface $entity
     */
    public function mapDataToEntity(array $data, $entity): ?NewsInterface
    {
        if (!isset($data['image'])) {
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

    public function create(string $locale): NewsInterface
    {
        $news = $this->newsRepository->create($locale);
        return $news;
    }
    public function save(NewsInterface $news): void
    {
        $this->newsRepository->save($news);
        $this->entityManager->flush();
    }
    public function persist(NewsInterface $news): void
    {
        $this->newsRepository->save($news);
    }
    public function remove(NewsInterface $news, bool $flush = true): void
    {
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
        return $removed;
    }

    public function copyPure(NewsInterface $source, NewsInterface $target): NewsInterface
    {
        $originalLocaleS = $source->hasLocale() ? $source->getLocale() : null;
        $originalLocaleT = $target->hasLocale() ? $target->getLocale() : null;
        $locales = $this->newsRepository->getLocales($source);
        $target
            ->setDefaultLocale($source->getDefaultLocale())
            ->setVisible($source->isVisible())
            ->setExternal($source->isExternal())
            ->setSource($source->getSource())
            ->setPublishDate($source->getPublishDate())
        ;

        foreach ($locales as $locale) {
            $source->setLocale($locale);
            $target->setLocale($locale);

            $target
                ->setTitle($source->getTitle())
                ->setExtension($source->getExtension())
                ->setDescription($source->getDescription())
                ->setContent($source->getContent())
            ;
        }
        if (null !== $originalLocaleS) {
            $source->setLocale($originalLocaleS);
        }
        if (null !== $originalLocaleT) {
            $target->setLocale($originalLocaleT);
        }
        $this->persist($source);
        $this->persist($target);
        return $target;
    }
    public function copyMutate(NewsInterface $source, NewsInterface $target, bool $override = false): NewsInterface
    {
        $originalLocaleS = $source->hasLocale() ? $source->getLocale() : null;
        $originalLocaleT = $target->hasLocale() ? $target->getLocale() : null;
        $locales = $this->newsRepository->getLocales($source);

        $this->setCategories(
            $target,
            \array_map(fn($category) => $category->getId(), $source->getCategories()->toArray())
        );
        $this->setTags(
            $target,
            \array_map(fn($tag) => $tag->getId(), $source->getTags()->toArray())
        );

        foreach ($locales as $locale) {
            $source->setLocale($locale);
            $target->setLocale($locale);

            if (null !== $source->getImage() || $override) {
                $img = $source->getImage();
                $targetImage = $target->getImage();
                if (null !== $targetImage) {
                    $this->customMediaManager->delete($targetImage);
                }
                $target->setImage($img);
            }

            $oldRoute = $source->getRoute();
            if (null === $oldRoute && false === $override) {
                continue;
            }
            $newRoute = $this->setRoute($target, $oldRoute?->getPath());

            if ($target->getTitle() !== $source->getTitle()) {
                // flip the routes
                $target->setRoute($oldRoute);
                $source->setRoute($newRoute);
                $newRoute?->setEntityId($source->getId());
                $oldRoute?->setEntityId($target->getId());
            }
        }
        if (null !== $originalLocaleS) {
            $source->setLocale($originalLocaleS);
        }
        if (null !== $originalLocaleT) {
            $target->setLocale($originalLocaleT);
        }
        $this->persist($source);
        $this->persist($target);
        return $target;
    }

    public function copy(NewsInterface $source, NewsInterface $target): NewsInterface
    {
        $this->copyPure($source, $target);
        $this->copyMutate($source, $target);
        return $target;
    }
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
