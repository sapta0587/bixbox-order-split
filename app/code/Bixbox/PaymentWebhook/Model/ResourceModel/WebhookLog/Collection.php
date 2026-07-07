<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Collection for the idempotency ledger. Provided for completeness / admin
 * grid use; the webhook path itself uses the resource model directly.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model\ResourceModel\WebhookLog;

use Bixbox\PaymentWebhook\Model\Data\WebhookLog;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

class Collection extends AbstractCollection
{
    protected function _construct(): void
    {
        $this->_init(
            WebhookLog::class,
            \Bixbox\PaymentWebhook\Model\ResourceModel\WebhookLog::class
        );
    }
}
