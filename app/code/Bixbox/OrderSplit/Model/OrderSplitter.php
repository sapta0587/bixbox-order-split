<?php
/**
 * Bixbox_OrderSplit
 *
 * Core order-splitting logic.
 *
 * The pure, dependency-free logic (computing the composite split key and
 * grouping quote items by that key) is exposed as static methods so it can be
 * unit-tested in isolation. The Magento-bound orchestration (reading the quote,
 * duplicating it per group and placing each as an order) lives in {@see split()}
 * and is covered by integration tests against a running Magento.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Model;

use Bixbox\OrderSplit\Model\ProductAttribute\Provider as AttributeProvider;
use Bixbox\OrderSplit\Model\Quote\QuoteDuplicatorInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Psr\Log\LoggerInterface;

class OrderSplitter
{
    /**
     * Key used in item descriptors for the catalog category dimension.
     */
    public const KEY_CATEGORY = 'category';

    /**
     * Key used in item descriptors for the vendor dimension.
     */
    public const KEY_VENDOR = 'vendor';

    /**
     * Key used in item descriptors for the warehouse-area dimension.
     */
    public const KEY_WAREHOUSE = 'warehouse';

    /**
     * @param Config $config
     * @param AttributeProvider $attributeProvider
     * @param QuoteDuplicatorInterface $quoteDuplicator
     * @param CartRepositoryInterface $cartRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly AttributeProvider $attributeProvider,
        private readonly QuoteDuplicatorInterface $quoteDuplicator,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Builds the composite split key for a single item descriptor, using only
     * the enabled dimensions. Pure function: no Magento dependencies.
     *
     * Example: with dimensions ['category','vendor','warehouse'] and a
     * descriptor {category: 4, vendor: 'Vendor A', warehouse: 'Warehouse North'}
     * the key is "category=4|vendor=Vendor A|warehouse=Warehouse North".
     *
     * @param array    $descriptor  {item_id:int, category:?int, vendor:?string, warehouse:?string}
     * @param string[] $dimensions  subset of {@see self::KEY_*}
     */
    public static function buildKey(array $descriptor, array $dimensions): string
    {
        $map = [
            self::KEY_CATEGORY => isset($descriptor[self::KEY_CATEGORY]) && $descriptor[self::KEY_CATEGORY] !== ''
                ? (string) $descriptor[self::KEY_CATEGORY]
                : '',
            self::KEY_VENDOR => isset($descriptor[self::KEY_VENDOR]) && $descriptor[self::KEY_VENDOR] !== ''
                ? (string) $descriptor[self::KEY_VENDOR]
                : '',
            self::KEY_WAREHOUSE => isset($descriptor[self::KEY_WAREHOUSE]) && $descriptor[self::KEY_WAREHOUSE] !== ''
                ? (string) $descriptor[self::KEY_WAREHOUSE]
                : '',
        ];

        $segments = [];
        // Iterate in a fixed order so keys are deterministic regardless of
        // the order $dimensions was supplied in.
        foreach ([self::KEY_CATEGORY, self::KEY_VENDOR, self::KEY_WAREHOUSE] as $dim) {
            if (in_array($dim, $dimensions, true)) {
                $segments[] = $dim . '=' . $map[$dim];
            }
        }
        return implode('|', $segments);
    }

    /**
     * Groups a list of item descriptors by their composite split key.
     * Pure function: no Magento dependencies.
     *
     * @param array    $descriptors  list of {item_id:int, category:?int, vendor:?string, warehouse:?string}
     * @param string[] $dimensions   subset of {@see self::KEY_*}
     * @return array<string,int[]>   split-key => list of item ids belonging to that key
     */
    public static function computeGroups(array $descriptors, array $dimensions): array
    {
        $groups = [];
        foreach ($descriptors as $descriptor) {
            $key = self::buildKey($descriptor, $dimensions);
            $groups[$key][] = (int) $descriptor['item_id'];
        }
        return $groups;
    }

    /**
     * Builds a plain descriptor for a quote item (Magento-bound).
     *
     * @return array{item_id:int,category:?int,vendor:?string,warehouse:?string}
     */
    public function buildItemDescriptor(Item $item): array
    {
        $product = $item->getProduct();
        return [
            'item_id' => (int) $item->getId(),
            self::KEY_CATEGORY => $product ? $this->attributeProvider->getFirstCategoryId($product) : null,
            self::KEY_VENDOR => $product ? $this->attributeProvider->getVendor($product) : null,
            self::KEY_WAREHOUSE => $product ? $this->attributeProvider->getWarehouseArea($product) : null,
        ];
    }

    /**
     * Determines whether the given quote should be split and, if so, places
     * one order per group by duplicating the quote (carrying only that group's
     * items) and invoking $placeOrder on each duplicate.
     *
     * The caller (the QuoteManagement plugin) supplies $placeOrder; this is the
     * already-advanced proceed closure of an around plugin, so calling it does
     * NOT re-enter this module's interception and thus needs no recursion guard.
     *
     * @param Quote       $quote
     * @param callable    $placeOrder  fn(int $cartId): int  (returns placed order id)
     * @return int[]  placed order ids; EMPTY when no split occurred (caller must place the original quote itself)
     */
    public function split(Quote $quote, callable $placeOrder): array
    {
        $storeId = $quote->getStoreId() ? (int) $quote->getStoreId() : null;

        if (!$this->config->isSplitEnabled($storeId)) {
            return [];
        }

        $dimensions = $this->config->getEnabledDimensions($storeId);
        if (empty($dimensions)) {
            return [];
        }

        // Build descriptors for top-level items only; children of configurable
        // / bundle products travel with their parent and are not split off.
        $descriptors = [];
        foreach ($quote->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                continue;
            }
            $descriptors[] = $this->buildItemDescriptor($item);
        }

        if (empty($descriptors)) {
            return [];
        }

        $groups = self::computeGroups($descriptors, $dimensions);

        if (count($groups) <= 1) {
            return [];
        }

        // One order per group.
        $orderIds = [];
        foreach ($groups as $itemIds) {
            try {
                $duplicate = $this->quoteDuplicator->duplicate($quote, $itemIds);
                $orderIds[] = (int) $placeOrder((int) $duplicate->getId());
            } catch (\Throwable $e) {
                // A failure placing one group must not abort the others; the
                // caller still owns the original quote and can retry. Log and
                // continue so partially-placed groups are still returned.
                $this->logger->error(
                    'Bixbox_OrderSplit: failed to place split order for group: ' . $e->getMessage(),
                    ['exception' => $e]
                );
            }
        }

        return $orderIds;
    }
}
