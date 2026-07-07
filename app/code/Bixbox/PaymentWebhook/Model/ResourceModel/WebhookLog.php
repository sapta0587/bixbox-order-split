<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Resource model for the idempotency ledger. The unique index on
 * payload_hash makes `loadByPayloadHash` + insert a single atomic dedupe
 * gate (see WebhookProcessor).
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model\ResourceModel;

use Bixbox\PaymentWebhook\Api\Data\WebhookLogInterface;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class WebhookLog extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('bixbox_payment_webhook_log', WebhookLogInterface::LOG_ID);
    }

    /**
     * Loads the row with the given payload_hash, if any. Returns the model
     * with its data populated (id == null when not found).
     */
    public function loadByPayloadHash(\Bixbox\PaymentWebhook\Model\Data\WebhookLog $log, string $payloadHash): void
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where(WebhookLogInterface::PAYLOAD_HASH . ' = ?', $payloadHash)
            ->limit(1);

        $row = $connection->fetchRow($select);
        if ($row) {
            $log->setData($row);
        } else {
            $log->setData([]);
        }
    }
}
