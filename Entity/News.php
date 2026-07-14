<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Entity;

use Kotaru\SuluUtils\Traits\ExternalEntityTrait;
use Kotaru\SuluUtils\Traits\IdTrait;
use Kotaru\SuluUtils\Traits\TagTrait;
use Kotaru\SuluUtils\Traits\LocaleTrait;
use Kotaru\SuluUtils\Traits\CategoryTrait;
use Doctrine\Common\Collections\Collection;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Component\Security\Authentication\UserInterface;

class News implements NewsInterface
{
    use IdTrait, LocaleTrait, TagTrait, ExternalEntityTrait, CategoryTrait;

    private bool $visible = false;

    private ?\DateTimeImmutable $publishDate = null;

    /** @var Collection<int, CategoryInterface> */
    private Collection $categories;

    /** @var Collection<int, TagInterface> */
    private Collection $tags;

    /** @var Collection<string, NewsTranslation> */
    private Collection $translations;

    private ?string $source = null;

    public function __construct()
    {
        $this->translations = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->categories = new ArrayCollection();
    }

    public function getTitle(): ?string
    {
        return $this->getTranslationValue(__FUNCTION__);
    }

    public function setTitle(?string $title): static
    {
        return $this->setTranslationValue(__FUNCTION__, $title);
    }

    public function getPath(): ?string
    {
        return $this->getTranslationValue(__FUNCTION__);
    }
    public function getRoute(): ?RouteInterface
    {
        return $this->getTranslationValue(__FUNCTION__);
    }

    public function setRoute(?RouteInterface $route): static
    {
        return $this->setTranslationValue(__FUNCTION__, $route);
    }

    public function getImageId(): ?array
    {
       return $this->getTranslationValue(__FUNCTION__);
       }
      
    public function getImage(): ?MediaInterface
    {
        return $this->getTranslationValue(__FUNCTION__);
    }
    public function setImage(?MediaInterface $image): static
    {
        return $this->setTranslationValue(__FUNCTION__, $image);
    }

    public function getDescription(): ?string
    {
        return $this->getTranslationValue(__FUNCTION__);
    }
    public function setDescription(?string $description): static
    {
        return $this->setTranslationValue(__FUNCTION__, $description);
    }

    public function getContent(): ?array
    {
        return $this->getTranslationValue(__FUNCTION__);
    }
    public function setContent(?array $content): static
    {
        return $this->setTranslationValue(__FUNCTION__, $content);
    }


    public function getPublishDate(): ?\DateTimeImmutable
    {
        return $this->publishDate;
    }

    public function setPublishDate(?\DateTimeImmutable $publishDate): static
    {
        $this->publishDate = $publishDate;

        return $this;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }
    public function setVisible(bool $visible): static
    {
        $this->visible = $visible;

        return $this;
    }


    public function getSource(): ?string
    {
        return $this->source;
    }
    public function setSource(?string $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getCreated(): \DateTimeInterface
    {
        return $this->getTranslationValue(__FUNCTION__, new \DateTime()) ?? new \DateTime();
    }
    public function setCreated(\DateTimeInterface $created): static
    {
        return $this->setTranslationValue(__FUNCTION__, $created);
    }

    public function getChanged(): \DateTimeInterface
    {
        return $this->getTranslationValue(__FUNCTION__, new \DateTime()) ?? new \DateTime();
    }
    public function setChanged(\DateTimeInterface $changed): static
    {
        return $this->setTranslationValue(__FUNCTION__, $changed);
    }

    public function getCreator(): ?UserInterface
    {
        return $this->getTranslationValue(__FUNCTION__);

    }
    public function setCreator(?UserInterface $creator): static
    {
        return $this->setTranslationValue(__FUNCTION__, $creator);
    }

    public function getChanger(): ?UserInterface
    {
        return $this->getTranslationValue(__FUNCTION__);
    }
    public function setChanger(?UserInterface $changer): static
    {
        return $this->setTranslationValue(__FUNCTION__, $changer);
    }


    public function getExtension(): array
    {
        return $this->getTranslationValue(__FUNCTION__, []);
    }
    public function addExtension(string $key, $content): static
    {
        return $this->setTranslationValue(__FUNCTION__, $key, $content);
    }
    public function removeExtension(string $key): static
    {
        return $this->setTranslationValue(__FUNCTION__, $key);
    }
    public function setExtension(array $extension): static
    {
        return $this->setTranslationValue(__FUNCTION__, $extension);
    }

    private function getTranslationValue(string $functionName, mixed $defaultValue = null)
    {
        $translation = $this->getTranslation($this->locale);
        if (!$translation instanceof NewsTranslation) {
            return $defaultValue ?? null;
        }
        return $translation->$functionName();
    }
    private function setTranslationValue(string $functionName, ...$values): static
    {
        $translation = $this->getTranslation($this->locale);
        if (!$translation instanceof NewsTranslation) {
            $translation = $this->createTranslation($this->locale);
        }
        if (\method_exists($translation, $functionName)) {
            $translation->$functionName(...$values);
        }
        return $this;
    }

    /**
     * @return array<string, NewsTranslation>
     */
    public function getTranslations(): array
    {
        return $this->translations->toArray();
    }

    public function getTranslation(string $locale): ?NewsTranslation
    {
        if (!$this->translations->containsKey($locale)) {
            return null;
        }

        return $this->translations->get($locale);
    }
    public function removeTranslation(NewsTranslation $translation): bool
    {
        return $this->translations->removeElement($translation);
    }
    public function getCurrentTranslation(): ?NewsTranslation
    {
        if (!$this->translations->containsKey($this->locale)) {
            return null;
        }

        return $this->translations->get($this->locale);
    }

    public function createTranslation(string $locale): NewsTranslation
    {
        $translation = new NewsTranslation($this, $locale);
        $this->translations->set($locale, $translation);

        return $translation;
    }
    public function addTranslation(string $locale, NewsTranslation $translation): NewsTranslation
    {
        if (!$this->translations->containsKey($locale)) {
            $this->translations->set($locale, $translation);
            $translation->setNews($this);
        }

        return $translation;
    }
    public function __clone(): void
    {
        if ($this->id) {
            $this->id = null;
        }
        $this->translations = $this->translations->map(fn($translation) => clone $translation);
        $this->categories = $this->categories->map(fn($category) => $category);
        $this->tags = $this->tags->map(fn($tag) => $tag);

        foreach ($this->translations as $translation) {
            $translation->setNews($this);
        }
    }
}
