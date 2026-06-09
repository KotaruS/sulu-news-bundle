<?php

declare(strict_types=1);

namespace Kotaru\Bundle\SuluNewsBundle\Content\DataProvider;

use Kotaru\Bundle\SuluNewsBundle\Admin\NewsAdmin;
use Kotaru\Bundle\SuluNewsBundle\Content\DataItem\NewsDataItem;
use Kotaru\Bundle\SuluNewsBundle\Entity\NewsInterface;
use Kotaru\Bundle\SuluNewsBundle\Repository\NewsRepository;
use JMS\Serializer\SerializationContext;
use Sulu\Bundle\MediaBundle\Api\Media as MediaApi;
use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Sulu\Bundle\WebsiteBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Component\Content\Compat\PropertyParameter;
use Sulu\Component\Serializer\ArraySerializerInterface;
use Sulu\Component\SmartContent\ArrayAccessItem;
use Sulu\Component\SmartContent\DataProviderResult;
use Sulu\Component\SmartContent\Orm\BaseDataProvider;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Security as SymfonyCoreSecurity;

class NewsDataProvider extends BaseDataProvider
{
    private MediaManagerInterface $mediaManager;
    private RequestStack $requestStack;

    public function __construct(
        NewsRepository $repository,
        ArraySerializerInterface $serializer,
        MediaManagerInterface $mediaManager,
        RequestStack $requestStack,
        ?ReferenceStoreInterface $referenceStore = null,
        Security|SymfonyCoreSecurity|null $security = null,
        ?RequestAnalyzerInterface $requestAnalyzer = null,
        $permissions = null
    ) {
        parent::__construct(
            $repository,
            $serializer,
            $referenceStore,
            $security,
            $requestAnalyzer,
            $permissions
        );
        $this->mediaManager = $mediaManager;
        $this->requestStack = $requestStack;
    }
    public function getConfiguration()
    {
        if (null === $this->configuration) {
            $this->configuration = self::createConfigurationBuilder()
                ->enableLimit()
                ->enablePagination()
                ->enableCategories()
                ->enableTags()
                ->enablePresentAs()
                ->enableSorting([
                    ['column' => 'translation.title', 'title' => 'app_admin.title'],
                    ['column' => 'publishDate', 'title' => 'app_admin.publish_date'],
                    ['column' => 'translation.created', 'title' => 'sulu_admin.created'],
                ])
                ->enableView(NewsAdmin::EDIT_FORM_VIEW, ['id' => 'id'])
                ->getConfiguration();
        }

        return $this->configuration;
    }

    protected function decorateDataItems(array $data)
    {
        return \array_map(
            fn(NewsInterface $item) => new NewsDataItem(
                $item,
                $item->getImage()
                ? $this->mediaManager->addFormatsAndUrl(new MediaApi($item->getImage(), $item->getLocale(), null))
                : null
            ),
            $data
        );
    }

    public function resolveDataItems(
        array $filters,
        array $propertyParameter,
        array $options = [],
        $limit = null,
        $page = 1,
        $pageSize = null
    ) {
        $options['types'] = isset($filters['types']) ? $filters['types'] : null;
        return parent::resolveDataItems(
            $filters,
            $propertyParameter,
            $options,
            $limit,
            $page,
            $pageSize
        );
    }

    public function resolveResourceItems(
        array $filters,
        array $propertyParameter,
        array $options = [],
        $limit = null,
        $page = 1,
        $pageSize = null
    ) {
        $options['types'] = isset($filters['types']) ? $filters['types'] : null;
        if ($filters['presentAs'] === 'highlight' && 1 === $page) {
            $pageSize++;
        }
        $parentResult = parent::resolveResourceItems(
            $filters,
            $propertyParameter,
            $options,
            $limit,
            $page,
            $pageSize
        );
        $items = $parentResult->getItems();

        return new DataProviderResult($this->decorateResourceItemsWithParameterData($items, $propertyParameter), $parentResult->getHasNextPage());
    }
    /**
     * Adds parameters to result.
     *
     * @param PropertyParameter[] $propertyParameter
     *
     * @return ArrayAccessItem[]
     */
    protected function decorateResourceItemsWithParameterData(array $items, $propertyParameter)
    {
        if (\array_key_exists('page_parameter', $propertyParameter)) {
            $items['view_params']['pageParameter'] = $propertyParameter['page_parameter']->getValue();
        }
        return $items;
    }

    /**
     * Returns additional options for query creation.
     * @see BaseDataProvider::getOptions
     *
     * @param PropertyParameter[] $propertyParameter
     *
     * @return array
     */
    protected function getOptions(
        array $propertyParameter,
        array $options = []
    ) {

        return ['types' => $options['types'] ?? null];
    }


    protected function getSerializationContext(): SerializationContext
    {
        return parent::getSerializationContext()->setGroups(['Other', 'fullNews']);
    }
}
