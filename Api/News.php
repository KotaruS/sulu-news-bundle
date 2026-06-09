<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Api;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Sulu\Component\Rest\ApiWrapper;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Bundle\MediaBundle\Api\Media as MediaApi;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;

/**
 * The News class which will be exported to the API.
 */
class News extends ApiWrapper
{
    private ?MediaApi $image = null;

    public function __construct(
        NewsInterface $news,
        ?string $locale = null,
    ) {
        $this->entity = $news;
        if (null === $locale) {
            $this->locale = $news->hasLocale() ? $news->getLocale() : $news->getDefaultLocale();
        } else {
            $this->entity->setLocale($locale);
            $this->locale = $locale;
        }
    }

    public function getId(): ?int
    {
        return $this->entity->getId();
    }

    public function getTitle(): ?string
    {
        return $this->entity->getTitle();
    }

    public function getRoute(): ?string
    {
        $route = $this->entity->getRoute();
        return $route ? $route->getPath() : null;
    }

    public function getDescription(): ?string
    {
        return $this->entity->getDescription();
    }


    public function getContent(): ?array
    {
        return $this->entity->getContent();
    }


    public function getImage(): ?array
    {
        if (null === $this->image) {
            return null;
        }

        return [
            'id' => $this->image->getId(),
            'url' => $this->image->getUrl(),
            'thumbnails' => $this->image->getFormats(),
        ];
    }

    /**
     * Sets the image as MediaApi
     */
    public function setImage(MediaApi $image): static
    {
        $this->image = $image;
        return $this;
    }

    public function isVisible(): bool
    {
        return $this->entity->isVisible();
    }

    public function isExternal(): bool
    {
        return $this->entity->isExternal();
    }
    public function getSource(): ?string
    {
        return $this->entity->getSource();
    }

    public function getPublishDate(): ?\DateTimeInterface
    {
        return $this->entity->getPublishDate();
    }


    public function getTags(): array
    {
        $tags = $this->entity->getTags();
        if ($tags->isEmpty()) {
            return [];
        }
        return \array_map(fn(TagInterface $tag) => $tag->getName(), $tags->toArray());
    }

    public function getCategories(): array
    {
        $categories = $this->entity->getCategories();
        if ($categories->isEmpty()) {
            return [];
        }
        return \array_map(fn(CategoryInterface $category) => $category->getId(), $categories->toArray());
    }

    public function getGhostLocale(): ?string
    {
        if (null === $this->entity->getTranslation($this->locale)) {
            return $this->entity->getDefaultLocale();
        }
        return null;
    }

    public function getAvailableLocales(): array
    {
        return $this->getLocales();
    }

    public function getContentLocales(): array
    {
        return $this->getLocales();
    }

    public function getExtension(): array
    {
        $extension = $this->entity->getExtension();
        return \array_replace_recursive([
            'seo' => [
                'title' => null,
                'description' => null,
                'keywords' => null,
                'canonicalUrl' => null,
                'noIndex' => null,
                'noFollow' => null,
                'hideInSitemap' => null,
            ],
            'excerpt' => [
                'title' => null,
                'description' => null,
            ]
        ], $extension);
    }

    private function getLocales(): array
    {
        $translations = $this->entity->getTranslations();

        return \array_keys($translations);
    }

}
