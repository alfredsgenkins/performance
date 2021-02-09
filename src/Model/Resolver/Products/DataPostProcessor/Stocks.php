<?php
/**
 * @category    ScandiPWA
 * @package     ScandiPWA_Performance
 * @author      Alfreds Genkins <info@scandiweb.com>
 * @copyright   Copyright (c) 2019 Scandiweb, Ltd (https://scandiweb.com)
 */

declare(strict_types=1);

namespace ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor;

use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Config\ScopeConfigInterface;
use ScandiPWA\Performance\Api\ProductsDataPostProcessorInterface;
use ScandiPWA\Performance\Model\Resolver\ResolveInfoFieldsTrait;
use Magento\CatalogInventory\Api\StockStatusCriteriaInterfaceFactory;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockStatusRepositoryInterface;

/**
 * Class Images
 * @package ScandiPWA\Performance\Model\Resolver\Products\DataPostProcessor
 */
class Stocks implements ProductsDataPostProcessorInterface
{
    use ResolveInfoFieldsTrait;

    const ONLY_X_LEFT_IN_STOCK = 'only_x_left_in_stock';

    const STOCK_STATUS = 'stock_status';

    const IN_STOCK = 'IN_STOCK';

    const OUT_OF_STOCK = 'OUT_OF_STOCK';

    /**
     * @var StockStatusCriteriaInterfaceFactory
     */
    protected $stock;

    /**
     * @var StockStatusRepositoryInterface
     */
    protected $stockStatusRepository;

    /**
     * @var StockConfigurationInterface
     */
    protected $stockConfiguration;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * Stocks constructor.
     * @param StockStatusCriteriaInterfaceFactory $stock
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param ScopeConfigInterface $scopeConfig
     * @param StockConfigurationInterface $stockConfiguration
     * @param StockStatusRepositoryInterface $stockItemRepository
     */
    public function __construct(
        StockStatusCriteriaInterfaceFactory $stock,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        ScopeConfigInterface $scopeConfig,
        StockConfigurationInterface $stockConfiguration,
        StockStatusRepositoryInterface $stockStatusRepository
    ) {
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->stockConfiguration = $stockConfiguration;
        $this->stockStatusRepository = $stockStatusRepository;
        $this->stock = $stock;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @param $node
     * @return string[]
     */
    protected function getFieldContent($node)
    {
        $stocks = [];
        $validFields = [
            self::ONLY_X_LEFT_IN_STOCK,
            self::STOCK_STATUS
        ];

        foreach ($node->selectionSet->selections as $selection) {
            if (!isset($selection->name)) {
                continue;
            };

            $name = $selection->name->value;

            if (in_array($name, $validFields)) {
                $stocks[] = $name;
            }
        }

        return $stocks;
    }

    /**
     * @inheritDoc
     */
    public function process(
        array $products,
        string $graphqlResolvePath,
        $graphqlResolveInfo,
        ?array $processorOptions = []
    ): callable {
        $productStocks = [];

        $fields = $this->getFieldsFromProductInfo(
            $graphqlResolveInfo,
            $graphqlResolvePath
        );

        if (!count($fields)) {
            return function (&$productData) {
            };
        }

        $productIds = array_map(function ($product) {
            return $product->getId();
        }, $products);

        $criteria = $this->stock->create();
        $criteria->addField('products_filter', $productIds);
        $criteria->setScopeFilter($this->stockConfiguration->getDefaultScopeId());
        $collection = $this->stockStatusRepository->getList($criteria);
        $stockStatuses = $collection->getItems();

        $thresholdQty = 0;

        if (in_array(self::ONLY_X_LEFT_IN_STOCK, $fields)) {
            $thresholdQty = (float) $this->scopeConfig->getValue(
                Configuration::XML_PATH_STOCK_THRESHOLD_QTY,
                ScopeInterface::SCOPE_STORE
            );
        }

        if (!count($stockStatuses)) {
            return function (&$productData) {
            };
        }

        foreach ($stockStatuses as $stockStatus) {
            $inStock = $stockStatus->getStockStatus() === StockStatusInterface::STATUS_IN_STOCK;

            $leftInStock = null;
            $qty = $stockStatus->getQty();

            if ($thresholdQty !== (float) 0) {
                $isThresholdPassed = $qty <= $thresholdQty;
                $leftInStock = $isThresholdPassed ? $qty : null;
            }

            $productStocks[$stockStatus->getProductId()] = [
                self::STOCK_STATUS => $inStock ? self::IN_STOCK : self::OUT_OF_STOCK,
                self::ONLY_X_LEFT_IN_STOCK => $leftInStock
            ];
        }

        return function (&$productData) use ($productStocks) {
            if (!isset($productData['entity_id'])) {
                return;
            }

            $productId = $productData['entity_id'];

            if (!isset($productStocks[$productId])) {
                return;
            }

            foreach ($productStocks[$productId] as $stockType => $stockData) {
                $productData[$stockType] = $stockData;
            }
        };
    }
}
