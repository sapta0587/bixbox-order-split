<?php
/**
 * Bixbox_OrderSplit
 *
 * Creates a persisted copy of a quote containing only the requested items.
 *
 * The duplicate mirrors the source's customer, billing/shipping addresses and
 * payment, then re-adds each requested top-level item via its buy request so
 * that configurable/bundle product options are preserved. Totals are
 * recalculated for the subset of items.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Model\Quote;

use Magento\Framework\DataObject;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Payment;
use Magento\Quote\Model\QuoteFactory;
use Psr\Log\LoggerInterface;

class QuoteDuplicator implements QuoteDuplicatorInterface
{
    /**
     * @param QuoteFactory $quoteFactory
     * @param CartRepositoryInterface $cartRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @inheritdoc
     */
    public function duplicate(Quote $source, array $itemIds): Quote
    {
        $itemIds = array_map('intval', $itemIds);

        $new = $this->quoteFactory->create();
        $new->setStoreId($source->getStoreId());
        $new->setCustomerIsGuest($source->getCustomerIsGuest());
        $new->setCustomerGroupId($source->getCustomerGroupId());
        $new->setCustomerEmail($source->getCustomerEmail());
        $new->setCustomerId($source->getCustomerId());
        $new->setQuoteCurrencyCode($source->getQuoteCurrencyCode());
        $new->setBaseCurrencyCode($source->getBaseCurrencyCode());
        $new->setStoreCurrencyCode($source->getStoreCurrencyCode());
        $new->setRemoteIp($source->getRemoteIp());
        $new->setReservedOrderId($source->getReservedOrderId()); // placeOrder will mint its own if needed

        // --- Billing address ---
        $billing = $source->getBillingAddress();
        if ($billing) {
            $new->setBillingAddress($this->cloneAddress($billing));
        }

        // --- Shipping address + method ---
        $shipping = $source->getShippingAddress();
        if ($shipping) {
            $clonedShipping = $this->cloneAddress($shipping);
            $clonedShipping->setShippingMethod($shipping->getShippingMethod());
            $clonedShipping->setShippingDescription($shipping->getShippingDescription());
            $clonedShipping->setCollectShippingRates(true);
            $new->setShippingAddress($clonedShipping);
        }

        // --- Payment ---
        $payment = $source->getPayment();
        if ($payment) {
            $clonedPayment = $this->clonePayment($payment);
            $new->setPayment($clonedPayment);
        }

        // --- Items (re-added via their buy request so product options survive) ---
        foreach ($source->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // children travel with their parent
            }
            if (!in_array((int) $item->getId(), $itemIds, true)) {
                continue;
            }
            $product = $item->getProduct();
            if (!$product) {
                continue;
            }
            try {
                $buyRequest = $item->getBuyRequest();
                if ($buyRequest instanceof DataObject) {
                    $buyRequest->setQty($item->getQty());
                    $new->addProduct($product, $buyRequest);
                } else {
                    $new->addProduct($product, (int) $item->getQty());
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'Bixbox_OrderSplit: failed to re-add quote item ' . $item->getId()
                    . ' to duplicated quote: ' . $e->getMessage(),
                    ['exception' => $e]
                );
                throw $e;
            }
        }

        $new->setTotalsCollectedFlag(false);
        $new->collectTotals();
        $this->cartRepository->save($new);

        return $new;
    }

    /**
     * Clones a quote address as a brand-new (unsaved) address so a new row is
     * inserted when the duplicate quote is saved.
     */
    private function cloneAddress(Address $source): Address
    {
        $clone = clone $source;
        $clone->setId(null);
        $clone->setQuoteId(null);
        $clone->setCustomerAddressId(null);
        $clone->setShippingAmount(null);
        $clone->setBaseShippingAmount(null);
        return $clone;
    }

    /**
     * Clones a quote payment as a brand-new (unsaved) payment, carrying over
     * the method and additional data.
     */
    private function clonePayment(Payment $source): Payment
    {
        $clone = clone $source;
        $clone->setId(null);
        $clone->setQuoteId(null);
        return $clone;
    }
}
