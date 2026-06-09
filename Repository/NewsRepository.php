<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Repository;

use Kotaru\Bundle\SuluNewsBundle\Entity\News;
use DateTimeImmutable;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Sulu\Component\Security\Authentication\UserInterface;
use Sulu\Component\SmartContent\Orm\DataProviderRepositoryTrait;
use Sulu\Component\SmartContent\Orm\DataProviderRepositoryInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * @extends ServiceEntityRepository<News>
 *
 * @method News|null find($id, $lockMode = null, $lockVersion = null)
 * @method News|null findOneBy(array $criteria, array $orderBy = null)
 * @method News[]    findAll()
 * @method News[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class NewsRepository extends ServiceEntityRepository implements DataProviderRepositoryInterface
{
    use DataProviderRepositoryTrait {
        findByFilters as findByFiltersTrait;
        findByFiltersIds as findByFiltersIdsTrait;
    }

    /**
     * @param array $filters
     * @param int $page
     * @param int $pageSize
     * @param int|null $limit
     * @param string $locale
     * @param array{webspaceKey?: string, locale?: string} $options
     * @param class-string|null $entityClass
     * @param string|null $entityAlias
     * @param int|null $permission
     *
     * @return mixed
     *
     * @see DataProviderRepositoryInterface::findByFilters
     */
    public function findByFilters(
        $filters,
        $page,
        $pageSize,
        $limit,
        $locale,
        $options = [],
        ?UserInterface $user = null,
        $entityClass = null,
        $entityAlias = null,
        $permission = null
    ) {
        $alias = 'entity';
        $queryBuilder = $this->createQueryBuilder($alias)
            ->addSelect($alias)
            ->where($alias . '.id IN (:ids)')
            ->orderBy($alias . '.id', 'ASC');
        $this->appendJoins($queryBuilder, $alias, $locale);

        if (isset($filters['sortBy'])) {
            $sortMethod = $filters['sortMethod'] ?? 'asc';
            $sortBy = false !== \strpos($filters['sortBy'], '.') ? $filters['sortBy'] : $alias . '.' . $filters['sortBy'];

            $this->appendSortBy($sortBy, $sortMethod, $queryBuilder, $alias, $locale);
        }

        $query = $queryBuilder->getQuery();
        unset($filters['types']);
        $ids = $this->findByFiltersIds(
            $filters,
            $page,
            $pageSize,
            $limit,
            $locale,
            $options,
            $user,
            $entityClass,
            $entityAlias,
            $permission
        );
        $query->setParameter('ids', $ids);

        $result = $query->getResult();
        foreach ($result as $item) {
            $item->setLocale($locale);
        }
        return $result;
    }

    /**
     * Copied from findByFiltersIds trait only changes marked by #CHANGED
     *
     * @param array $filters array of filters: tags, tagOperator
     * @param int $page
     * @param int $pageSize
     * @param int|null $limit
     * @param string $locale
     * @param mixed[] $options
     * @param class-string $entityClass
     *
     * @return int[]|string[]
     */
    private function findByFiltersIds(
        $filters,
        $page,
        $pageSize,
        $limit,
        $locale,
        $options = [],
        ?UserInterface $user = null,
        ?string $entityClass = null,
        ?string $entityAlias = null,
        ?int $permission = null
    ) {
        $parameter = [];

        $alias = 'entity';
        $queryBuilder = $this->createQueryBuilder($alias)
            ->select($alias . '.id')
            ->distinct()
            ->orderBy($alias . '.id', 'ASC');

        $tagRelation = $this->appendTagsRelation($queryBuilder, $alias);
        $categoryRelation = $this->appendCategoriesRelation($queryBuilder, $alias);

        if (isset($filters['sortBy'])) {
            $sortMethod = $filters['sortMethod'] ?? 'asc';
            $sortBy = false !== \strpos($filters['sortBy'], '.') ? $filters['sortBy'] : $alias . '.' . $filters['sortBy'];

            $this->appendSortBy($sortBy, $sortMethod, $queryBuilder, $alias, $locale);
            $queryBuilder->addSelect($sortBy);
        }

        $parameter = $this->append($queryBuilder, $alias, $locale, $options);

        if (isset($filters['dataSource'])) {
            $includeSubFolders = $this->getBoolean($filters['includeSubFolders'] ?? false);
            $parameter = \array_merge(
                $parameter,
                $this->appendDatasource($filters['dataSource'], $includeSubFolders, $queryBuilder, $alias)
            );
        }

        if (isset($filters['tags']) && !empty($filters['tags'])) {
            $parameter = \array_merge(
                $parameter,
                $this->appendRelation(
                    $queryBuilder,
                    $tagRelation,
                    $filters['tags'],
                    \strtolower($filters['tagOperator']),
                    'adminTags'
                )
            );
        }

        if (isset($filters['types']) && !empty($filters['types'])) {
            $typeRelation = $this->appendTypeRelation($queryBuilder, $alias);
            $parameter = \array_merge(
                $parameter,
                $this->appendRelation(
                    $queryBuilder,
                    $typeRelation,
                    $filters['types'],
                    'or',
                    'typeId'
                )
            );
        }

        if (isset($filters['categories']) && !empty($filters['categories'])) {
            $parameter = \array_merge(
                $parameter,
                $this->appendRelation(
                    $queryBuilder,
                    $categoryRelation,
                    $filters['categories'],
                    \strtolower($filters['categoryOperator']),
                    'adminCategories'
                )
            );
        }

        if (isset($filters['targetGroupId']) && $filters['targetGroupId']) {
            $targetGroupRelation = $this->appendTargetGroupRelation($queryBuilder, $alias);
            $parameter = \array_merge(
                $parameter,
                $this->appendRelation(
                    $queryBuilder,
                    $targetGroupRelation,
                    [$filters['targetGroupId']],
                    'and',
                    'targetGroupId'
                )
            );
        }

        if (isset($filters['websiteTags']) && !empty($filters['websiteTags'])) {
            $parameter = \array_merge(
                $parameter,
                $this->appendRelation(
                    $queryBuilder,
                    $tagRelation,
                    $filters['websiteTags'],
                    \strtolower($filters['websiteTagsOperator']),
                    'websiteTags'
                )
            );
        }

        if (isset($filters['websiteCategories']) && !empty($filters['websiteCategories'])) {
            $parameter = \array_merge(
                $parameter,
                $this->appendRelation(
                    $queryBuilder,
                    $categoryRelation,
                    $filters['websiteCategories'],
                    \strtolower($filters['websiteCategoriesOperator']),
                    'websiteCategories'
                )
            );
        }

        if ($this->accessControlQueryEnhancer && $entityClass && $entityAlias && $permission) {
            $this->accessControlQueryEnhancer->enhance(
                $queryBuilder,
                $user,
                $permission,
                $entityClass,
                $entityAlias
            );
        }

        $query = $queryBuilder->getQuery();
        foreach ($parameter as $name => $value) {
            $query->setParameter($name, $value);
        }

        if (null !== $page && $pageSize > 0) {
            $pageOffset = ($page - 1) * $pageSize;
            // #CHANGED
            if ('highlight' === $filters['presentAs'] && $page > 1) {
                $pageOffset++;
            }
            $restLimit = $limit - $pageOffset;

            // if limitation is smaller than the page size then use the rest limit else use page size plus 1 to
            // determine has next page
            $maxResults = (null !== $limit && $pageSize > $restLimit ? $restLimit : ($pageSize + 1));

            if ($maxResults <= 0) {
                return [];
            }

            $query->setMaxResults($maxResults);
            $query->setFirstResult($pageOffset);
        } elseif (null !== $limit) {
            $query->setMaxResults($limit);
        }

        return \array_map(
            function ($item) {
                /** @var int|string */
                return $item['id'];
            },
            $query->getScalarResult()
        );
    }

    /**
     * Append additional condition to query builder for "findByFilters" function.
     *
     * @param string $alias
     * @param string $locale
     * @param mixed[] $options
     *
     * @return array<string, int|string|int[]|string[]> parameters for query
     */
    protected function append(QueryBuilder $queryBuilder, $alias, $locale, $options = [])
    {
        $parameters = ['locale' => $locale, 'date' => new DateTimeImmutable()];
        $queryBuilder
            ->leftJoin($alias . '.translations', 'translation', 'WITH', 'translation.locale = :locale', 'translation.locale')
            ->andWhere($alias . '.visible = true')
            // ->andWhere($alias . '.external = false')
            ->andWhere('(' . $alias . '.publishDate <= :date) OR (' . $alias . '.publishDate IS NULL)')
        ;

        return $parameters;
    }


    protected function appendJoins(QueryBuilder $queryBuilder, $alias, $locale)
    {
        $queryBuilder
            ->addSelect('translation')
            ->addSelect('image')
            ->addSelect('route')
            ->leftJoin($alias . '.translations', 'translation', 'WITH', 'translation.locale = :locale', 'translation.locale')
            ->leftJoin('translation.route', 'route')
            ->leftJoin('translation.image', 'image')
            ->andWhere('translation.news = entity') // fails if no translation is available
            // ->andWhere($alias . '.visible = true')
            // ->andWhere($alias . '.external = false')
            ->setParameter('locale', $locale)
        ;
    }

    // protected function appendSortByJoins(QueryBuilder $queryBuilder, $alias, $locale)
    // {
    //     $queryBuilder
    //         ->leftJoin($alias . '.translations', 'translation', 'WITH', 'translation.locale = :locale', 'translation.locale')
    //         ->andWhere($alias . '.visible = true')
    //         ->andWhere($alias . '.external = false')
    //         ->andWhere('(' . $alias . '.startDate <= :date) OR (' . $alias . '.startDate IS NULL)')
    //         ->andWhere('(' . $alias . '.endDate >= :date) OR (' . $alias . '.endDate IS NULL)')
    //         ->setParameter('locale', $locale)
    //         ->setParameter('date', new \DateTimeImmutable())
    //     ;
    // }

    protected function appendTypeRelation(QueryBuilder $queryBuilder, $alias)
    {
        return $alias . '.visible';
    }

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, News::class);
    }

    public function findById($id, string $locale): ?News
    {
        $news = $this->find($id);
        if (!$news instanceof News) {
            return null;
        }

        $news->setLocale($locale);

        return $news;
    }
    /**
     * @return News[]|null
     */
    public function findByIds($ids, string $locale): ?array
    {
        if (!$ids) {
            return null;
        }
        $news = $this->findBy(['id' => $ids]);

        $this->setLocales($news, $locale);

        return $news;
    }

    public function findAllWithLocale(string $locale): ?array
    {
        $news = $this->findAll();
        $newsWithLocale = [];
        foreach ($news as $oneNews) {
            if (null !== $oneNews->getTranslation($locale)) {
                $oneNews->setLocale($locale);
                $newsWithLocale[] = $oneNews;
            }
        }

        if (!$news || empty($newsWithLocale)) {
            return null;
        }

        return $newsWithLocale;
    }

    /**
     * Get only of getIdsWithLocalizedContent
     *
     * @return string[]|null
     */
    public function getIdsWithLocalizedContent(string $locale): ?array
    {
        return $this->createQueryBuilder('e')
            ->select('e.id')
            ->leftJoin('e.translations', 'et')
            ->where('et.locale = :locale')
            ->setParameter('locale', $locale)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Gets a list of news based on filters
     *
     * Filters:
     * [3m] – News in the last 3 months;
     * [all] – All news;
     * [current] – currently running news;
     * [upcoming] – upcoming news;
     * [atchive] – already expired news;
     *
     * @param string $locale locale of the translation
     * @param string $filter comma separeted list of filters e.g. [current,archive,upcoming,3m,all]
     * @return News[]
     */
    public function findActive(string $locale, string $filter = 'current')
    {
        $qb = $this->createQueryBuilder('news');
        $qb
            ->select('news')
            ->addSelect('translation')
            ->addSelect('image')
            ->addSelect('route')
            ->leftJoin('news.translations', 'translation', 'WITH', 'translation.locale = :locale', 'translation.locale')
            ->leftJoin('translation.route', 'route')
            ->leftJoin('translation.image', 'image')
            ->where('translation.locale = :locale')
            ->andWhere('translation.news = news') // fails if no translation is available
            ->andWhere('news.visible = true')
            ->andWhere('news.external = false')
            ->setParameter('locale', $locale)
        ;

        $news = $qb->getQuery()
            ->getResult();
        return $this->setLocales($news, $locale);
    }

    /**
     * @param string $locale
     * @param null|int $limit
     * @param array $filters e.g month: datetime
     * @return News[]
     */
    public function findRandom(string $locale, ?int $limit = null, array $filters = []): array
    {
        $fetchLimit = $limit ? $limit * 2 : $limit;
        $qb = $this->createQueryBuilder('news');
        $qb
            ->select('news')
            ->addSelect('translation')
            ->addSelect('image')
            ->addSelect('route')
            ->join('news.translations', 'translation', 'WITH', 'translation.locale = :locale', 'translation.locale')
            ->leftJoin('translation.route', 'route')
            ->leftJoin('translation.image', 'image')
            ->where('translation.locale = :locale')
            ->andWhere('translation.news = news') // fails if no translation is available
            ->andWhere('news.visible = true')
            ->andWhere('news.external = false')
            ->groupBy('news')
            ->setParameter('locale', $locale)
        ;
        if ($limit) {
            $qb->setMaxResults($fetchLimit);
        }

        $products = $qb->getQuery()
            ->getResult();
        $this->setLocales($products, $locale);
        \shuffle($products);
        return \array_slice($products, 0, $limit ?? 100);
    }
    public function findRelated(string $locale, ?int $limit = null, array $filters = []): array
    {
        $fetchLimit = $limit ? $limit * 2 : $limit;
        $qb = $this->createQueryBuilder('news');
        $qb
            ->select('news')
            ->addSelect('translation')
            ->addSelect('image')
            ->addSelect('route')
            ->join('news.translations', 'translation', 'WITH', 'translation.locale = :locale', 'translation.locale')
            ->leftJoin('news.categories', 'cats')
            ->leftJoin('translation.route', 'route')
            ->leftJoin('translation.image', 'image')
            ->where('translation.locale = :locale')
            ->andWhere('translation.news = news') // fails if no translation is available
            ->andWhere('news.visible = true')
            // ->andWhere('news.external = false')
            ->groupBy('news.id')
            ->setParameter('locale', $locale)
        ;
        if (isset($filters['categories'])) {
            $qb->andWhere('cats.key IN (:categories)')
                ->setParameter('categories', $filters['categories']);
        }
        if (isset($filters['date'])) {
            $qb->orderBy('ABS(DATE_DIFF(news.publishDate, :date))', 'ASC')
                ->setParameter('date', $filters['date']);
        }
        if (isset($filters['exclude'])) {
            $qb->andWhere('news.id NOT IN (:excluded)')
                ->setParameter('excluded', $filters['exclude']);
        }
        if ($limit) {
            $qb->setMaxResults($fetchLimit);
        }
        $news = $qb->getQuery()
            ->getResult();
        // dd($qb->getQuery()->getSQL());
        $this->setLocales($news, $locale);
        return \array_slice($news, 0, $limit ?? 100);
    }

    public function create(string $locale): News
    {
        $news = new News();
        $news->setDefaultLocale($locale);
        return $news->setLocale($locale);
    }

    public function save(News $news): void
    {
        $em = $this->getEntityManager();
        $em->persist($news);
    }
    public function detach(News $news): void
    {
        $em = $this->getEntityManager();
        $em->detach($news);
    }

    public function flush(): void
    {
        $em = $this->getEntityManager();
        $em->flush();
    }

    public function remove(int $id): void
    {
        $em = $this->getEntityManager();

        $news = $this->find($id);

        if (!$news instanceof News) {
            return;
        }


        $em->remove($news);
    }

    protected function setLocales(array $news, string $locale): ?array
    {
        foreach ($news as $oneNews) {
            $oneNews->setLocale($locale);
        }

        return $news;
    }
    public function getLocales(News $news): ?array
    {
        $translations = $news->getTranslations();
        return \array_keys($translations);
    }
}
