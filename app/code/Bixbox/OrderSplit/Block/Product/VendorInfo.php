<?php
/**
 * Bixbox_OrderSplit
 *
 * Block exposing a product's vendor + warehouse area to the storefront.
 * Used on both the product detail page (current product from the registry)
 * and the product list page (product set explicitly via {@see setProduct()}).
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Block\Product;

use Bixbox\OrderSplit\Model\Config;
use Bixbox\OrderSplit\Model\ProductAttribute\Provider;
use Magento\Catalog\Model\Product;
use Magento\Framework\Registry;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class VendorInfo extends Template
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param Config $config
     * @param Provider $attributeProvider
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Registry $registry,
        private readonly Config $config,
        private readonly Provider $attributeProvider,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Returns the product this block should render data for: the product
     * explicitly attached via {@see setProduct()} when used in a list loop,
     * or the current product from the registry on the detail page.
     */
    public function getProduct(): ?Product
    {
        $product = $this->getData('product');
        if ($product instanceof Product) {
            return $product;
        }
        $product = $this->registry->registry('current_product');
        return $product instanceof Product ? $product : null;
    }

    public function getVendor(): ?string
    {
        $product = $this->getProduct();
        return $product ? $this->attributeProvider->getVendor($product) : null;
    }

    public function getWarehouseArea(): ?string
    {
        $product = $this->getProduct();
        return $product ? $this->attributeProvider->getWarehouseArea($product) : null;
    }

    /**
     * Whether the info should be rendered on the product detail page, taking
     * both the admin config flag and the existence of at least one value into
     * account (no point rendering an empty block).
     */
    public function canShowOnProductView(): bool
    {
        if (!$this->config->isDisplayedOnProductView()) {
            return false;
        }
        return $this->hasAnyValue();
    }

    public function canShowOnProductList(): bool
    {
        if (!$this->config->isDisplayedOnProductList()) {
            return false;
        }
        return $this->hasAnyValue();
    }

    private function hasAnyValue(): bool
    {
        return $this->getVendor() !== null || $this->getWarehouseArea() !== null;
    }
}
