<?php
/**
 * Bixbox_OrderSplit
 *
 * Contract for duplicating a quote carrying only a subset of its items.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Model\Quote;

use Magento\Quote\Model\Quote;

interface QuoteDuplicatorInterface
{
    /**
     * Creates and persists a new quote that mirrors $source (customer,
     * addresses, payment, store, currency) but contains only the quote items
     * whose id is in $itemIds.
     *
     * @param Quote  $source
     * @param int[]  $itemIds  ids of TOP-LEVEL quote items to carry over
     * @return Quote the newly created, saved quote
     */
    public function duplicate(Quote $source, array $itemIds): Quote;
}
