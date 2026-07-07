<?php
/**
 * Bixbox_OrderSplit
 *
 * Scope-config wrapper for the module's admin configuration. All flags live
 * under the `bixbox_ordersplit/*` config namespace.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'bixbox_ordersplit/general/enabled';
    public const XML_PATH_ON_PDP = 'bixbox_ordersplit/display/on_product_view';
    public const XML_PATH_ON_PLP = 'bixbox_ordersplit/display/on_product_list';
    public const XML_PATH_BY_CATEGORY = 'bixbox_ordersplit/split/by_category';
    public const XML_PATH_BY_VENDOR = 'bixbox_ordersplit/split/by_vendor';
    public const XML_PATH_BY_WAREHOUSE = 'bixbox_ordersplit/split/by_warehouse';

    public const DIMENSION_CATEGORY = 'category';
    public const DIMENSION_VENDOR = 'vendor';
    public const DIMENSION_WAREHOUSE = 'warehouse';

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether order splitting is enabled (master switch).
     */
    public function isSplitEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDisplayedOnProductView(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ON_PDP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function isDisplayedOnProductList(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ON_PLP,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function splitByCategory(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BY_CATEGORY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function splitByVendor(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BY_VENDOR,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function splitByWarehouse(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BY_WAREHOUSE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Returns the subset of split dimensions that are currently enabled, in a
     * deterministic order. Used by the splitter to know which key parts matter.
     *
     * @return string[]  One or more of {@see self::DIMENSION_*}
     */
    public function getEnabledDimensions(?int $storeId = null): array
    {
        $dims = [];
        if ($this->splitByCategory($storeId)) {
            $dims[] = self::DIMENSION_CATEGORY;
        }
        if ($this->splitByVendor($storeId)) {
            $dims[] = self::DIMENSION_VENDOR;
        }
        if ($this->splitByWarehouse($storeId)) {
            $dims[] = self::DIMENSION_WAREHOUSE;
        }
        return $dims;
    }
}
