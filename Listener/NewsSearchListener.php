<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Listener;

use Kotaru\Bundle\SuluNewsBundle\Event\NewsSearchDeindexEvent;
use Kotaru\Bundle\SuluNewsBundle\Event\NewsSearchIndexEvent;
use Massive\Bundle\SearchBundle\Search\SearchManagerInterface;

class NewsSearchListener
{
    public function __construct(
        private readonly SearchManagerInterface $searchManager,
    ) {
    }

    public function reindex(NewsSearchIndexEvent $event): void
    {
        $news = $event->getEntity();

        foreach ($news->getTranslations() as $locale => $translation) {
            $this->searchManager->index($translation);
        }

    }


    public function deindex(NewsSearchDeindexEvent $event): void
    {
        $news = $event->getEntity();

        foreach ($news->getTranslations() as $locale => $translation) {
            $this->searchManager->deindex($translation);
        }
    }
}
