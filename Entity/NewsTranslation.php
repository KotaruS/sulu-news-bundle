<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Entity;

use Kotaru\SuluUtils\Traits\IdTrait;
use Kotaru\SuluUtils\Traits\EntityExtensionTrait;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Component\Persistence\Model\AuditableTrait;
use Sulu\Component\Persistence\Model\AuditableInterface;

class NewsTranslation implements AuditableInterface
{
    use IdTrait, AuditableTrait, EntityExtensionTrait;

    private ?string $title = null;

    private ?RouteInterface $route = null;

    private ?string $description = null;

    private ?array $content = null;

    private ?MediaInterface $image = null;

    private NewsInterface $news;

    private string $locale;

    public function __construct(NewsInterface $news, string $locale)
    {
        $this->news = $news;
        $this->locale = $locale;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }
    public function setTitle(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getLocale(): ?string
    {
        return $this->locale;
    }
    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }
  public function getPath(): ?string
    {
        $route = $this->getRoute();
        if (!$route) {
            return null;
        }
        $locale = $this->getLocale();
        $path = $route->getPath();
        if ($locale !== 'cs') {
            $path = '/' . $locale . $path;
        }
        return $path;
    }
    public function getRoute(): ?RouteInterface
    {
        return $this->route;
    }
    public function setRoute(?RouteInterface $route): static
    {
        $this->route = $route;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getContent(): ?array
    {
        return $this->content;
    }
    public function setContent(?array $content): static
    {
        $this->content = $content;

        return $this;
    }
    public function getImage(): ?MediaInterface
    {
        return $this->image;
    }

    public function getImageId(): ?array
    {
        if (isset($this->image)) {
            return ['id' => $this->image->getId()];
        }
        return null;
    }
    public function setImage(?MediaInterface $image): static
    {
        $this->image = $image;

        return $this;
    }

    public function getNews(): NewsInterface
    {
        return $this->news;
    }

    public function setNews(NewsInterface $news): static
    {
        $this->news = $news;

        return $this;
    }

    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
        }
        $this->setRoute(null);
    }
}
