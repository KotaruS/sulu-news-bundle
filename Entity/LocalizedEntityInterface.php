<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Entity;

/**
 * Doctrine Entity classes implementing this interface must be able to access default locale.
 */
interface LocalizedEntityInterface
{


    /**  @return string */
    public function getLocale();
    public function hasLocale(): bool;
    public function setLocale(string $locale): static;

    public function getDefaultLocale(): ?string;
    public function setDefaultLocale(string $defaultLocale): static;

}
