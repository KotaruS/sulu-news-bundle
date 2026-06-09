<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Routing;


use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Sulu\Bundle\RouteBundle\Routing\Defaults\RouteDefaultsProviderInterface;

class NewsRouteDefaultProvider implements RouteDefaultsProviderInterface
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getByEntity($entityClass, $id, $locale, $object = null)
    {
        $entityRepository = $this->entityManager->getRepository($entityClass);

        return [
            '_controller' => 'Kotaru\Bundle\SuluNewsBundle\Controller\Website\NewsWebsiteController::indexAction',
            'entity' => $object ?: $entityRepository->findById((int) $id, $locale),
        ];
    }

    public function isPublished($entityClass, $id, $locale)
    {
        $entityRepository = $this->entityManager->getRepository($entityClass);

        $entity = $entityRepository->findById((int) $id, $locale);
        if (!$this->supports($entityClass) || !$this->supportedEntity($entity)) {
            return false;
        }

        if ($entity instanceof News) {
            $published = $entity->getPublishDate() === null ? true : $entity->getPublishDate() <= new DateTimeImmutable();
            return ($entity->isVisible() && $published) || ($entity->isExternal() && $published);
        }

        return true;
    }

    public function supportedEntity($entity): bool
    {
        return $entity instanceof News;
    }

    public function supports($entityClass): bool
    {
        return $entityClass === News::class;
    }

}
