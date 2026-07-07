<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Resolves a Magento order id from a gateway payment_id by reading the
 * bixbox_payment_id column on sales_order_payment. Returns the order id
 * (or null when no payment row carries that id).
 *
 * The lookup uses the read-sales adapter and an explicit index on
 * bixbox_payment_id, so it is a single indexed equality select.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\OrderPaymentInterface;

class OrderFinder
{
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    public function findOrderIdByPaymentId(string $paymentId): ?int
    {
        $connection = $this->resourceConnection->getConnection('read');
        $table = $this->resourceConnection->getTableName('sales_order_payment');

        $select = $connection->select()
            ->from($table, [OrderPaymentInterface::PARENT_ID])
            ->where('bixbox_payment_id = ?', $paymentId)
            ->limit(1);

        $orderId = $connection->fetchOne($select);
        return $orderId === false ? null : (int) $orderId;
    }
}
