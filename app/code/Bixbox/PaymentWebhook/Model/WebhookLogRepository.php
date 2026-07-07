<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Default repository implementation for the idempotency log. Uses the
 * auto-generated WebhookLog factory so the model gets a proper context.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

use Bixbox\PaymentWebhook\Api\Data\WebhookLogInterface;
use Bixbox\PaymentWebhook\Api\WebhookLogRepositoryInterface;
use Bixbox\PaymentWebhook\Model\Data\WebhookLog;
use Bixbox\PaymentWebhook\Model\Data\WebhookLogFactory;
use Bixbox\PaymentWebhook\Model\ResourceModel\WebhookLog as WebhookLogResource;

class WebhookLogRepository implements WebhookLogRepositoryInterface
{
    public function __construct(
        private readonly WebhookLogResource $resource,
        private readonly WebhookLogFactory $webhookLogFactory
    ) {
    }

    public function getByPayloadHash(string $payloadHash): ?WebhookLogInterface
    {
        $log = $this->webhookLogFactory->create();
        $this->resource->loadByPayloadHash($log, $payloadHash);
        return $log->getId() === null ? null : $log;
    }

    public function save(WebhookLogInterface $log): WebhookLogInterface
    {
        $this->resource->save($log);
        return $log;
    }
}
