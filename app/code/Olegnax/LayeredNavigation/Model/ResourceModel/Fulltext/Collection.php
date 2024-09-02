<?php
/**
 * @author      Olegnax
 * @package     Olegnax_LayeredNavigation
 * @copyright   Copyright (c) 2019 Olegnax (http://olegnax.com/). All rights reserved.
 * @license     Proprietary License https://olegnax.com/license/
 */

namespace Olegnax\LayeredNavigation\Model\ResourceModel\Fulltext;

use Magento\Catalog\Model\Category;
use Magento\Catalog\Model\Indexer\Product\Flat\State;
use Magento\Catalog\Model\Layer\Filter\Dynamic\AlgorithmFactory;
use Magento\Catalog\Model\Product\OptionFactory;
use Magento\Catalog\Model\ResourceModel\Helper;
use Magento\Catalog\Model\ResourceModel\Url;
use Magento\CatalogInventory\Helper\Stock;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Model\Session;
use Magento\Eav\Model\Config;
use Magento\Eav\Model\EntityFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Data\Collection\Db\FetchStrategyInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\StateException;
use Magento\Framework\Module\Manager;
use Magento\Framework\Phrase;
use Magento\Framework\Search\Adapter\Mysql\TemporaryStorage;
use Magento\Framework\Search\Adapter\Mysql\TemporaryStorageFactory;
use Magento\Framework\Search\Response\QueryResponse;
use Magento\Framework\Stdlib\DateTime;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\Validator\UniversalFactory;
use Magento\Search\Model\QueryFactory;
use Magento\Search\Model\SearchEngine;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Olegnax\LayeredNavigation\Model\Request\Builder;
use Olegnax\LayeredNavigation\Model\Request\BuilderFactory;
//use Magento\Framework\Search\Request\Builder;
//use Magento\Framework\Search\Request\BuilderFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Fulltext Collection
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Collection extends \Magento\Catalog\Model\ResourceModel\Product\Collection
{
    /**
     * @var  QueryResponse
     */
    private $queryResponse;

    /**
     * Catalog search data
     *
     * @var QueryFactory
     */
    private $queryFactory = null;

    /**
     * @var Builder
     */
    private $requestBuilder;

    /**
     * @var SearchEngine
     */
    private $searchEngine;

    /**
     * @var string
     */
    private $queryText;

    /**
     * @var string|null
     */
    private $relevanceOrderDirection;

    /**
     * @var string
     */
    private $searchRequestName;

    /**
     * @var TemporaryStorageFactory
     */
    private $temporaryStorageFactory;

    /**
     * @var Stock
     */
    private $stockHelper;

    /**
     * @var Builder
     */
    private $memRequestBuilder;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * Collection constructor.
     * @param \Magento\Framework\Data\Collection\EntityFactory $entityFactory
     * @param LoggerInterface $logger
     * @param FetchStrategyInterface $fetchStrategy
     * @param ManagerInterface $eventManager
     * @param Config $eavConfig
     * @param ResourceConnection $resource
     * @param EntityFactory $eavEntityFactory
     * @param Helper $resourceHelper
     * @param UniversalFactory $universalFactory
     * @param StoreManagerInterface $storeManager
     * @param Manager $moduleManager
     * @param State $catalogProductFlatState
     * @param ScopeConfigInterface $scopeConfig
     * @param OptionFactory $productOptionFactory
     * @param Url $catalogUrl
     * @param TimezoneInterface $localeDate
     * @param Session $customerSession
     * @param DateTime $dateTime
     * @param GroupManagementInterface $groupManagement
     * @param QueryFactory $queryFactory
     * @param BuilderFactory $requestBuilderFactory
     * @param SearchEngine $searchEngine
     * @param TemporaryStorageFactory $temporaryStorageFactory
     * @param ProductMetadataInterface $productMetadata
     * @param Stock $stockHelper
     * @param null $connection
     * @param string $searchRequestName
     */
    public function __construct(
        \Magento\Framework\Data\Collection\EntityFactory $entityFactory,
        LoggerInterface $logger,
        FetchStrategyInterface $fetchStrategy,
        ManagerInterface $eventManager,
        Config $eavConfig,
        ResourceConnection $resource,
        EntityFactory $eavEntityFactory,
        Helper $resourceHelper,
        UniversalFactory $universalFactory,
        StoreManagerInterface $storeManager,
        Manager $moduleManager,
        State $catalogProductFlatState,
        ScopeConfigInterface $scopeConfig,
        OptionFactory $productOptionFactory,
        Url $catalogUrl,
        TimezoneInterface $localeDate,
        Session $customerSession,
        DateTime $dateTime,
        GroupManagementInterface $groupManagement,
        QueryFactory $queryFactory,
        BuilderFactory $requestBuilderFactory,
        SearchEngine $searchEngine,
        TemporaryStorageFactory $temporaryStorageFactory,
        ProductMetadataInterface $productMetadata,
        Stock $stockHelper,
        $connection = null,
        $searchRequestName = 'catalog_view_container'
    ) {
        $this->queryFactory = $queryFactory;
        $this->searchRequestName = $searchRequestName;
        parent::__construct(
            $entityFactory,
            $logger,
            $fetchStrategy,
            $eventManager,
            $eavConfig,
            $resource,
            $eavEntityFactory,
            $resourceHelper,
            $universalFactory,
            $storeManager,
            $moduleManager,
            $catalogProductFlatState,
            $scopeConfig,
            $productOptionFactory,
            $catalogUrl,
            $localeDate,
            $customerSession,
            $dateTime,
            $groupManagement,
            $connection
        );

        $this->requestBuilder = $requestBuilderFactory->create();
        $this->searchEngine = $searchEngine;
        $this->temporaryStorageFactory = $temporaryStorageFactory;
        $this->stockHelper = $stockHelper;
        $this->productMetadata = $productMetadata;
    }

    /**
     * Add search query filter
     *
     * @param string $query
     * @return $this
     */
    public function addSearchFilter($query)
    {
        $this->queryText = trim($this->queryText . ' ' . $query);
        return $this;
    }

    /**
     * @inheritdoc
     */
    public function setRequestData($builder)
    {
        $this->_select->reset();
        $this->requestBuilder = $builder;
        $this->queryResponse = null;
        $this->_isFiltersRendered = false;
    }

    /**
     * @return Builder
     */
    public function getMemRequestBuilder()
    {
        if ($this->memRequestBuilder === null) {
            $this->memRequestBuilder = clone $this->requestBuilder;
            $this->memRequestBuilder->bindDimension('scope', $this->getStoreId());
            if ($this->queryText) {
                $this->memRequestBuilder->bind('search_term', $this->queryText);
            }

            $priceRangeCalculation = $this->_scopeConfig->getValue(
                AlgorithmFactory::XML_PATH_RANGE_CALCULATION,
                ScopeInterface::SCOPE_STORE
            );
            if (is_null($priceRangeCalculation)) {
                $priceRangeCalculation = '';
            }
            if ($priceRangeCalculation) {
                $this->memRequestBuilder->bind('price_dynamic_algorithm', $priceRangeCalculation);
            }

            $this->memRequestBuilder->setRequestName($this->searchRequestName);
        }
        return $this->memRequestBuilder;
    }

    /**
     * Stub method for compatibility with other search engines
     *
     * @return $this
     */
    public function setGeneralDefaultQuery()
    {
        return $this;
    }

    /**
     * @param $field
     * @param QueryResponse|null $response
     * @return array
     */
    public function getFacetedData($field, QueryResponse $response = null)
    {
        if (!$response) {
            $this->_renderFilters();
            $response = $this->queryResponse;
        }

        $aggregations = $response->getAggregations();
        $bucket = $aggregations->getBucket($field . '_bucket');
        $result = [];
        if (!$bucket) {
            throw new StateException(__('Bucket does not exist'));
        }

        foreach ($bucket->getValues() as $value) {
            $metrics = $value->getMetrics();
            $result[$metrics['value']] = $metrics;
        }

        return $result;
    }

    /**
     * @return $this
     */
    protected function _renderFilters()
    {
        $this->_filters = [];
        return parent::_renderFilters();
    }

    /**
     * Specify category filter for product collection
     *
     * @param Category $category
     * @return $this
     */
    public function addCategoryFilter(Category $category)
    {
        $this->addFieldToFilter('category_ids', $category->getId());
        return parent::addCategoryFilter($category);
    }

    /**
     * Apply attribute filter to facet collection
     *
     * @param string $field
     * @param null $condition
     * @return $this
     */
    public function addFieldToFilter($field, $condition = null)
    {
        if ($this->queryResponse !== null) {
            throw new RuntimeException('Illegal state');
        }

        if (!is_array($condition) || !in_array(key($condition), ['from', 'to'], true)) {
            // See app/code/Magento/Catalog/Model/ResourceModel/Product/Indexer/Eav/AbstractEav::_getIndexableAttributesCondition()
            // Visibility filter wasn't in index before 2.2
            if ($field != 'visibility'
                || version_compare($this->productMetadata->getVersion(), '2.2.0', '>=')) {
                $this->requestBuilder->bind($field, $condition);
            }
        } else {
            if (!empty($condition['from'])) {
                $this->requestBuilder->bind("{$field}.from", $condition['from']);
            }
            if (!empty($condition['to'])) {
                $this->requestBuilder->bind("{$field}.to", $condition['to']);
            }
        }

        return $this;
    }

    /**
     * Set product visibility filter for enabled products
     *
     * @param array $visibility
     * @return $this
     */
    public function setVisibility($visibility)
    {
        $this->addFieldToFilter('visibility', $visibility);
        return parent::setVisibility($visibility);
    }

    /**
     * Filter Product by Categories using index table
     *
     * @param array $categoriesFilter
     * @return $this
     */
    public function addIndexCategoriesFilter(array $categoriesFilter)
    {
        foreach ($categoriesFilter as $conditionType => $values) {
            $categorySelect = $this->getConnection()->select()->from(
                ['cat' => $this->getTable('catalog_category_product_index')],
                'cat.product_id'
            )->where($this->getConnection()->prepareSqlCondition('cat.category_id', ['in' => $values]));
            $selectCondition = [
                $this->mapConditionType($conditionType) => $categorySelect,
            ];
            $whereCondition = $this->getConnection()->prepareSqlCondition('e.entity_id', $selectCondition);
            $this->getSelect()->where($whereCondition);
            $this->requestBuilder->bind('category_ids', $values);
        }
        return $this;
    }

    /**
     * Map equal and not equal conditions to in and not in
     *
     * @param string $conditionType
     * @return mixed
     */
    private function mapConditionType($conditionType)
    {
        $conditionsMap = [
            'eq' => 'in',
            'neq' => 'nin',
        ];
        return isset($conditionsMap[$conditionType]) ? $conditionsMap[$conditionType] : $conditionType;
    }

    /**
     * Load product collection before filters rendering
     */
    protected function _renderFiltersBefore()
    {
        $this->requestBuilder->bindDimension('scope', $this->getStoreId());
        if ($this->queryText) {
            $this->requestBuilder->bind('search_term', $this->queryText);
        }

        $priceRangeCalculation = $this->_scopeConfig->getValue(
            AlgorithmFactory::XML_PATH_RANGE_CALCULATION,
            ScopeInterface::SCOPE_STORE
        );
        if ($priceRangeCalculation) {
            $this->requestBuilder->bind('price_dynamic_algorithm', $priceRangeCalculation);
        }

        $this->requestBuilder->setRequestName($this->searchRequestName);
        $this->memRequestBuilder = clone $this->requestBuilder;
        $queryRequest = $this->requestBuilder->create();
        $this->queryResponse = $this->searchEngine->search($queryRequest);

        $temporaryStorage = $this->temporaryStorageFactory->create();
        $table = $temporaryStorage->storeApiDocuments($this->queryResponse->getIterator());

        $this->getSelect()->joinInner(
            [
                'search_result' => $table->getName(),
            ],
            'e.entity_id = search_result.' . TemporaryStorage::FIELD_ENTITY_ID,
            []
        );

        return parent::_renderFiltersBefore();
    }

    /**
     * @return $this
     */
    protected function _beforeLoad()
    {
        $this->setOrder('entity_id');
        $this->stockHelper->addIsInStockFilterToCollection($this);

        return parent::_beforeLoad();
    }

    /**
     * Set Order field
     *
     * @param string $attribute
     * @param string $dir
     * @return $this
     */
    public function setOrder($attribute, $dir = Select::SQL_DESC)
    {
        if ($attribute === 'relevance') {
            $this->relevanceOrderDirection = $dir;
        } else {
            parent::setOrder($attribute, $dir);
        }

        return $this;
    }

    /**
     * Add order by entity_id
     *
     * @return $this
     */
    protected function _renderOrders()
    {
        if (!$this->_isOrdersRendered) {
            if ($this->relevanceOrderDirection) {
                $this->getSelect()->order(
                    'search_result.' . TemporaryStorage::FIELD_SCORE . ' ' . $this->relevanceOrderDirection
                );
            }

            parent::_renderOrders();
        }

        return $this;
    }
}
