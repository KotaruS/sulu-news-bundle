<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Entity;

use Doctrine\Common\Collections\Collection;
use Sulu\Bundle\TagBundle\Tag\TagInterface;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Bundle\MediaBundle\Entity\MediaInterface;
use Sulu\Bundle\RouteBundle\Model\RoutableInterface;
use Sulu\Bundle\CategoryBundle\Entity\CategoryInterface;
use Sulu\Component\Security\Authentication\UserInterface;

interface NewsInterface extends RoutableInterface, LocalizedEntityInterface
{
    public const RESOURCE_KEY = 'news';
    public const SERIALIZATION_GROUPS = ['fullNews'];
    public const PARTIAL_SERIALIZATION_GROUPS = ['partialNews', 'fullNews'];

    public function getTitle(): ?string;
    public function setTitle(?string $title): static;

    public function getRoute(): ?RouteInterface;
    public function setRoute(?RouteInterface $route): static;

    public function getDescription(): ?string;
    public function setDescription(?string $description): static;

    public function getContent(): ?array;
    public function setContent(?array $content): static;

    public function getImage(): ?MediaInterface;
    public function setImage(?MediaInterface $image): static;

    public function getPublishDate(): ?\DateTimeImmutable;
    public function setPublishDate(?\DateTimeImmutable $date): static;

    public function isVisible(): ?bool;
    public function setVisible(bool $visible): static;

    public function isExternal(): bool;
    public function setExternal(bool $external): static;

    public function getSource(): ?string;
    public function setSource(?string $source): static;

    /**  @return Collection<int, CategoryInterface> */
    public function getCategories(): Collection;
    public function hasCategory(CategoryInterface $category): bool;
    public function setCategories(Collection $categories): static;
    public function addCategory(CategoryInterface $category): static;
    public function clearCategories(): static;

    /**  @return Collection<int, TagInterface> */
    public function getTags(): Collection;
    public function hasTag(TagInterface $tag): bool;
    public function setTags(Collection $tags): static;
    public function addTag(TagInterface $tag): static;
    public function clearTags(): static;

    //  Auditable
    public function getCreated(): \DateTimeInterface;
    public function setCreated(\DateTimeInterface $created): static;

    public function getChanged(): \DateTimeInterface;
    public function setChanged(\DateTimeInterface $changed): static;

    public function getCreator(): ?UserInterface;
    public function setCreator(?UserInterface $creator): static;

    public function getChanger(): ?UserInterface;
    public function setChanger(?UserInterface $changer): static;

    // Extension
    public function getExtension(): array;
    public function addExtension(string $key, $content): static;
    public function removeExtension(string $key): static;
    public function setExtension(array $extension): static;

    //  Translation
    /** @return array<string, NewsTranslation> */
    public function getTranslations(): array;
    public function getTranslation(string $locale): ?NewsTranslation;
    public function removeTranslation(NewsTranslation $translation): bool;
    public function getCurrentTranslation(): ?NewsTranslation;
    public function createTranslation(string $locale): NewsTranslation;
    public function addTranslation(string $locale, NewsTranslation $translation): NewsTranslation;

}
