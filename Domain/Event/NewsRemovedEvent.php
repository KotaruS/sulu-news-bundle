<?php

namespace Kotaru\Bundle\SuluNewsBundle\Domain\Event;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Sulu\Bundle\ActivityBundle\Domain\Event\DomainEvent;

class NewsRemovedEvent extends DomainEvent
{

    private int $newsId;

    private string $newsTitle;

    public function __construct(
        int $newsId,
        string $newsTitle,
    ) {
        parent::__construct();

        $this->newsId = $newsId;
        $this->newsTitle = $newsTitle;
    }

    public function getEventType(): string
    {
        return 'removed';
    }

    public function getResourceKey(): string
    {
        return NewsInterface::RESOURCE_KEY;
    }

    public function getResourceId(): string
    {
        return (string) $this->newsId;
    }

    public function getResourceTitle(): ?string
    {
        return $this->newsTitle;
    }

    public function getResourceSecurityContext(): ?string
    {
        return NewsAdmin::SECURITY_CONTEXT;
    }
}
