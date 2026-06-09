<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Content\DataItem;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use JMS\Serializer\Annotation as JMS;
use Sulu\Component\SmartContent\ItemInterface;
use Sulu\Bundle\MediaBundle\Api\Media as MediaApi;

#[JMS\ExclusionPolicy('all')]
class NewsDataItem implements ItemInterface
{
    private readonly NewsInterface $entity;
    private readonly ?MediaApi $media;

    public function __construct(
        NewsInterface $news,
        ?MediaApi $media = null,
    ) {
        $this->entity = $news;
        $this->media = $media;
    }

    #[JMS\SerializedName('id')]
    #[JMS\VirtualProperty]
    public function getId(): ?int
    {
        return $this->entity->getId();
    }
    #[JMS\SerializedName('image')]
    #[JMS\VirtualProperty]
    public function getImage(): ?string
    {
        if (null === $this->media || !\array_key_exists('sulu-40x40', $thumbnails = $this->media->getThumbnails())) {
            return null;
        }

        return $thumbnails['sulu-40x40'];
    }

    #[JMS\SerializedName('title')]
    #[JMS\VirtualProperty]
    public function getTitle(): ?string
    {
        return $this->entity->getTitle();
    }

    public function getResource(): NewsInterface
    {
        return $this->entity;
    }

}
