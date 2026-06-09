<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Repository;

use Kotaru\Bundle\SuluNewsBundle\Entity\NewsTranslation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsTranslation>
 *
 * @method NewsTranslation|null find($id, $lockMode = null, $lockVersion = null)
 * @method NewsTranslation|null findOneBy(array $criteria, array $orderBy = null)
 * @method NewsTranslation[] findAll()
 * @method NewsTranslation[] findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NewsTranslationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsTranslation::class);
    }
}
