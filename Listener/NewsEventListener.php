<?php

namespace Kotaru\Bundle\SuluNewsBundle\Listener;

use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsCreatedEvent;
use Kotaru\Bundle\SuluNewsBundle\Domain\Event\NewsModifiedEvent;
use Sulu\Bundle\WebsiteBundle\Cache\CacheClearerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Bundle\WebsiteBundle\ReferenceStore\WebspaceReferenceStore;


class NewsEventListener
{
    public function __construct(
        private CacheClearerInterface $cacheClearer,
        private WebspaceManagerInterface $webspaceManager,

    ) {
    }

    public function clearPageCache(NewsCreatedEvent|NewsModifiedEvent $event): void
    {
        $tags = [];
        foreach ($this->webspaceManager->getWebspaceCollection() as $webspace) {
            $webspaceKey = $webspace->getKey();
            $tags[] = WebspaceReferenceStore::generateTagByWebspaceKey($webspaceKey);
        }
        /** @phpstan-ignore arguments.count */
        $this->cacheClearer->clear(empty($tags) ? null : $tags);

    }
}
