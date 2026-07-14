<?php

namespace Kotaru\Bundle\SuluNewsBundle\Domain\Event;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

class NewsCreatedEvent extends DomainEvent
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
        return 'created';
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
    public function getResourceEntity(): NewsInterface
    {
        return $this->news;
    }

    public function getResourceSecurityContext(): ?string
    {
        return NewsAdmin::SECURITY_CONTEXT;
    }
}
