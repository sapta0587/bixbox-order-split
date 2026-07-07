<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Immutable, typed view of a payment-gateway webhook payload after the
 * Normalizer has safely extracted the well-known fields out of the dynamic
 * raw array. Carries only what the rest of the pipeline needs; everything
 * else is dropped (the raw payload is persisted separately for audit).
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model\Payload;

class WebhookPayload
{
    /**
     * @param string|null $paymentId   Gateway payment id (the order lookup key). Null when absent.
     * @param string|null $status      Normalized-lowercase gateway status (e.g. "paid", "authorize"). Null when absent.
     * @param string|null $vaCode      Virtual-account code from payment_detail, if present.
     * @param string|null $qrCode      QR code from payment_detail, if present.
     * @param array       $items       Items[] as received, each {sku, qty} best-effort. Empty when absent.
     * @param string|null $customerEmail Customer email from customer.email, if present.
     */
    public function __construct(
        public readonly ?string $paymentId,
        public readonly ?string $status,
        public readonly ?string $vaCode,
        public readonly ?string $qrCode,
        public readonly array $items,
        public readonly ?string $customerEmail
    ) {
    }

    public function isValid(): bool
    {
        return $this->paymentId !== null && $this->paymentId !== '' && $this->status !== null && $this->status !== '';
    }
}
