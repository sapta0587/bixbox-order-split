<?php
/**
 * Bixbox_OrderSplit
 *
 * Around-plugin on Magento\Quote\Model\QuoteManagement::placeOrder.
 *
 * When an order is about to be placed, the plugin asks {@see OrderSplitter}
 * whether the quote needs splitting (by category / vendor / warehouse area).
 *
 *  - No split needed  -> the original quote is placed normally via $proceed.
 *  - Split needed     -> one duplicate quote per group is placed via $proceed
 *                        (each carries only that group's items), the original
 *                        quote is marked inactive, and the first sub-order id
 *                        is returned to the checkout flow.
 *
 * $proceed is the already-advanced around-plugin chain closure, so calling it
 * for the duplicate quotes does NOT re-enter this plugin — no recursion guard
 * is required.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Plugin\Quote;

use Bixbox\OrderSplit\Model\OrderSplitter;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteManagement;
use Psr\Log\LoggerInterface;

class QuoteManagementPlugin
{
    /**
     * @param OrderSplitter $orderSplitter
     * @param CartRepositoryInterface $cartRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly OrderSplitter $orderSplitter,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param QuoteManagement    $subject
     * @param callable           $proceed    fn($cartId, $orderId = null): int
     * @param int                $cartId
     * @param string|int|null    $orderId
     * @return int  placed order id (the first one when the order was split)
     * @noinspection PhpUnusedParameterInspection
     */
    public function aroundPlaceOrder(
        QuoteManagement $subject,
        callable $proceed,
        $cartId,
        $orderId = null
    ): int {
        $cartId = (int) $cartId;

        try {
            $quote = $this->cartRepository->get($cartId);
        } catch (\Throwable $e) {
            // Cannot load the quote (e.g. already inactive / not found) -> fall
            // back to the default placement flow and let Magento handle it.
            return (int) $proceed($cartId, $orderId);
        }

        $orderIds = $this->orderSplitter->split(
            $quote,
            static function (int $subCartId) use ($proceed, $orderId) {
                return (int) $proceed($subCartId, $orderId);
            }
        );

        if (empty($orderIds)) {
            // No split: place the original quote normally.
            return (int) $proceed($cartId, $orderId);
        }

        // Split happened: the original quote's items were placed across the
        // duplicate sub-quotes, so the original itself must be deactivated to
        // avoid leaving an active cart behind.
        try {
            $quote->setIsActive(0);
            $this->cartRepository->save($quote);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Bixbox_OrderSplit: failed to deactivate original quote '
                . $cartId . ' after split: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }

        return (int) reset($orderIds);
    }
}
