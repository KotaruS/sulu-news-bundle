<?php

namespace Kotaru\Bundle\SuluNewsBundle\Domain\Event;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

class NewsModifiedEvent extends DomainEvent
{

    private NewsInterface $news;

    public function __construct(
        NewsInterface $news,
    ) {
        parent::__construct();

        $this->news = $news;
    }

    public function getEventType(): string
    {
        return 'modified';
    }

    public function getResourceKey(): string
    {
        return NewsInterface::RESOURCE_KEY;
    }

    public function getResourceId(): string
    {
        return (string) $this->news->getId();
    }

    public function getResourceTitle(): ?string
    {
        return $this->news->getTitle();
    }

    public function getResourceLocale(): ?string
    {
        return $this->news->getLocale();
    }


    public function getResourceSecurityContext(): ?string
    {
        return NewsAdmin::SECURITY_CONTEXT;
    }
}
