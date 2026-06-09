<?php

namespace Kotaru\Bundle\SuluNewsBundle\Twig;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Manager\NewsManager;
use JMS\Serializer\SerializationContext;
use Sulu\Component\Serializer\ArraySerializerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * @version 1.0.0
 * Provides news articles based on same category.
 */
class NewsExtension extends AbstractExtension
{


    public function __construct(
        private NewsManager $newsManager,
        private ArraySerializerInterface $serializer,
        private string $defaultLocale,
    ) {
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('get_related_news', [$this, 'getRelatedNews']),
        ];
    }

    public function getRelatedNews($uuid, $locale = '', int $limit = 3, ): array
    {
        if (empty($locale)) {
            $locale = $this->defaultLocale;
        }
        $news = $this->newsManager->getRelated($uuid, $locale);
        if (empty($news)) {
            return [];
        }
        return \array_map(fn($news) => $this->serializer->serialize($news, SerializationContext::create()
            ->setSerializeNull(true)
            ->setGroups([
                'Default',
                NewsInterface::SERIALIZATION_GROUPS[0],
            ])), $news);
    }


}
