<?php

namespace Kotaru\Bundle\SuluNewsBundle\Event;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Symfony\Contracts\EventDispatcher\Event;

class NewsSearchDeindexEvent extends Event
{
    public function __construct(private readonly NewsInterface $entity)
    {
    }

    public function getEntity(): NewsInterface
    {
        return $this->entity;
    }
}
