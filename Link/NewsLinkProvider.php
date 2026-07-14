<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Link;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use Sulu\Bundle\MarkupBundle\Markup\Link\LinkConfiguration;
use Sulu\Bundle\MarkupBundle\Markup\Link\LinkConfigurationBuilder;
use Sulu\Bundle\MarkupBundle\Markup\Link\LinkItem;
use Sulu\Bundle\MarkupBundle\Markup\Link\LinkProviderInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class NewsLinkProvider implements LinkProviderInterface
{

    public function __construct(
        private NewsRepository $newsRepository,
        private TranslatorInterface $translator
    ) {}

    public function getConfiguration(): LinkConfiguration
    {
        return LinkConfigurationBuilder::create()
            ->setTitle($this->translator->trans('sulu_news',[],'admin'))
            ->setResourceKey(NewsInterface::RESOURCE_KEY) // the resourceKey of the entity that should be loaded
            ->setListAdapter('table')
            ->setDisplayProperties(['title'])
            ->setOverlayTitle($this->translator->trans('sulu_news',[],'admin'))
            ->setEmptyText($this->translator->trans('sulu_news.empty_list',[],'admin'))
            ->setIcon('su-news')
            ->getLinkConfiguration();
    }

    public function preload(array $hrefs, $locale, $published = true): array
    {
        if (0 === count($hrefs)) {
            return [];
        }

        $result = [];
        $elements = $this->newsRepository->findByIds($hrefs, $locale); // load items by id
        foreach ($elements as $element) {
            $element->setLocale($locale);
            $result[] = new LinkItem($element->getId(), $element->getTitle(), $element->getPath(), $element->isVisible());
        }

        return $result;
    }
}
