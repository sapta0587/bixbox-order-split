<?php
/**
 * Bixbox_OrderSplit
 *
 * Resolves the split-relevant attributes (vendor, warehouse area and the
 * product's first catalog category) from a product. Used by both the product
 * page display block and the order-splitter.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Model\ProductAttribute;

use Bixbox\OrderSplit\Setup\Patch\Data\AddVendorAndWarehouseAttributes;
use Magento\Catalog\Model\Product;

class Provider
{
    /**
     * Returns the (frontend) label of the product's `vendor` attribute, e.g.
     * "Vendor A", or null when the product has no vendor assigned.
     */
    public function getVendor(Product $product): ?string
    {
        $value = $product->getAttributeText(AddVendorAndWarehouseAttributes::VENDOR_ATTRIBUTE);
        return $this->normalise($value);
    }

    /**
     * Returns the (frontend) label of the product's `warehouse_area` attribute,
     * e.g. "Warehouse North", or null when none is assigned.
     */
    public function getWarehouseArea(Product $product): ?string
    {
        $value = $product->getAttributeText(AddVendorAndWarehouseAttributes::WAREHOUSE_AREA_ATTRIBUTE);
        return $this->normalise($value);
    }

    /**
     * Returns the first category id assigned to the product, or null when the
     * product is not categorised. This is the "catalog category" used as a
     * split dimension (documented assumption in the README).
     */
    public function getFirstCategoryId(Product $product): ?int
    {
        $ids = $product->getCategoryIds();
        if (!is_array($ids) || empty($ids)) {
            return null;
        }
        return (int) reset($ids);
    }

    /**
     * `getAttributeText()` may return an array (multiselect), a string, or
     * false/null/empty. We only deal with select attributes here so a single
     * string is expected; anything else is normalised to null.
     */
    private function normalise(mixed $value): ?string
    {
        if (is_array($value)) {
            return null;
        }
        if ($value === false || $value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }
}
