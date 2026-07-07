<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Idempotency-ledger row model. Backed by bixbox_payment_webhook_log.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model\Data;

use Bixbox\PaymentWebhook\Api\Data\WebhookLogInterface;
use Magento\Framework\Model\AbstractModel;

class WebhookLog extends AbstractModel implements WebhookLogInterface
{
    protected function _construct(): void
    {
        $this->_init(\Bixbox\PaymentWebhook\Model\ResourceModel\WebhookLog::class);
    }

    public function getLogId(): ?int
    {
        return $this->hasData(self::LOG_ID) ? (int) $this->getData(self::LOG_ID) : null;
    }

    public function setLogId(?int $logId): WebhookLogInterface
    {
        return $this->setData(self::LOG_ID, $logId === null ? null : (int) $logId);
    }

    public function getPaymentId(): ?string
    {
        return $this->getData(self::PAYMENT_ID) === null ? null : (string) $this->getData(self::PAYMENT_ID);
    }

    public function setPaymentId(?string $paymentId): WebhookLogInterface
    {
        return $this->setData(self::PAYMENT_ID, $paymentId);
    }

    public function getPayloadHash(): ?string
    {
        return $this->getData(self::PAYLOAD_HASH) === null ? null : (string) $this->getData(self::PAYLOAD_HASH);
    }

    public function setPayloadHash(?string $hash): WebhookLogInterface
    {
        return $this->setData(self::PAYLOAD_HASH, $hash);
    }

    public function getStatus(): ?string
    {
        return $this->getData(self::STATUS) === null ? null : (string) $this->getData(self::STATUS);
    }

    public function setStatus(?string $status): WebhookLogInterface
    {
        return $this->setData(self::STATUS, $status);
    }

    public function getAction(): ?string
    {
        return $this->getData(self::ACTION) === null ? null : (string) $this->getData(self::ACTION);
    }

    public function setAction(?string $action): WebhookLogInterface
    {
        return $this->setData(self::ACTION, $action);
    }

    public function getOrderId(): ?int
    {
        return $this->hasData(self::ORDER_ID) && $this->getData(self::ORDER_ID) !== null
            ? (int) $this->getData(self::ORDER_ID)
            : null;
    }

    public function setOrderId(?int $orderId): WebhookLogInterface
    {
        return $this->setData(self::ORDER_ID, $orderId === null ? null : (int) $orderId);
    }

    public function getOrderState(): ?string
    {
        return $this->getData(self::ORDER_STATE) === null ? null : (string) $this->getData(self::ORDER_STATE);
    }

    public function setOrderState(?string $state): WebhookLogInterface
    {
        return $this->setData(self::ORDER_STATE, $state);
    }

    public function getPayload(): ?string
    {
        return $this->getData(self::PAYLOAD) === null ? null : (string) $this->getData(self::PAYLOAD);
    }

    public function setPayload(?string $payload): WebhookLogInterface
    {
        return $this->setData(self::PAYLOAD, $payload);
    }

    public function getIsDuplicate(): bool
    {
        return (bool) $this->getData(self::IS_DUPLICATE);
    }

    public function setIsDuplicate(bool $flag): WebhookLogInterface
    {
        return $this->setData(self::IS_DUPLICATE, $flag);
    }

    public function getReceivedAt(): ?string
    {
        return $this->getData(self::RECEIVED_AT) === null ? null : (string) $this->getData(self::RECEIVED_AT);
    }

    public function setReceivedAt(?string $receivedAt): WebhookLogInterface
    {
        return $this->setData(self::RECEIVED_AT, $receivedAt);
    }
}
