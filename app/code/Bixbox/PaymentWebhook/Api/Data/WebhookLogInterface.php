<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Data interface for the bixbox_payment_webhook_log row (idempotency ledger).
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Api\Data;

interface WebhookLogInterface
{
    public const LOG_ID = 'log_id';
    public const PAYMENT_ID = 'payment_id';
    public const PAYLOAD_HASH = 'payload_hash';
    public const STATUS = 'status';
    public const ACTION = 'action';
    public const ORDER_ID = 'order_id';
    public const ORDER_STATE = 'order_state';
    public const PAYLOAD = 'payload';
    public const IS_DUPLICATE = 'is_duplicate';
    public const RECEIVED_AT = 'received_at';

    public function getLogId(): ?int;
    public function setLogId(?int $logId): self;

    public function getPaymentId(): ?string;
    public function setPaymentId(?string $paymentId): self;

    public function getPayloadHash(): ?string;
    public function setPayloadHash(?string $hash): self;

    public function getStatus(): ?string;
    public function setStatus(?string $status): self;

    public function getAction(): ?string;
    public function setAction(?string $action): self;

    public function getOrderId(): ?int;
    public function setOrderId(?int $orderId): self;

    public function getOrderState(): ?string;
    public function setOrderState(?string $state): self;

    public function getPayload(): ?string;
    public function setPayload(?string $payload): self;

    public function getIsDuplicate(): bool;
    public function setIsDuplicate(bool $flag): self;

    public function getReceivedAt(): ?string;
    public function setReceivedAt(?string $receivedAt): self;
}
