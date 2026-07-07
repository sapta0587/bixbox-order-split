<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Pure, dependency-free mapping from a normalized gateway status string to the
 * Magento order state/status transition the webhook should apply.
 *
 * The mapping is intentionally conservative: unknown statuses map to a no-op
 * (state untouched, action=none) rather than a guess, so the webhook never
 * corrupts an order on a status it doesn't recognise. Returning action=none
 * is still idempotent-logged so the caller sees the webhook was received.
 *
 * Exposed as static so it is unit-testable without a Magento bootstrap.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

class StatusMapper
{
    public const ACTION_INVOICE = 'invoice';
    public const ACTION_CANCEL = 'cancel';
    public const ACTION_NONE = 'none';

    public const STATE_PROCESSING = 'processing';
    public const STATE_PENDING_PAYMENT = 'pending_payment';
    public const STATE_CANCELED = 'canceled';
    public const STATE_HOLDED = 'holded';
    public const STATE_COMPLETE = 'complete';

    /**
     * @var array<string,array{state:string,action:string}>
     *     Lowercase gateway status => target Magento state + the action the
     *     OrderUpdater should perform. States use Magento's OrderInterface
     *     state codes (not the human-readable status labels).
     */
    private const MAP = [
        'paid' => ['state' => self::STATE_PROCESSING, 'action' => self::ACTION_INVOICE],
        'settlement' => ['state' => self::STATE_PROCESSING, 'action' => self::ACTION_INVOICE],
        'capture' => ['state' => self::STATE_PROCESSING, 'action' => self::ACTION_INVOICE],
        'captured' => ['state' => self::STATE_PROCESSING, 'action' => self::ACTION_INVOICE],
        'success' => ['state' => self::STATE_PROCESSING, 'action' => self::ACTION_INVOICE],
        'succeeded' => ['state' => self::STATE_PROCESSING, 'action' => self::ACTION_INVOICE],

        'authorize' => ['state' => self::STATE_PENDING_PAYMENT, 'action' => self::ACTION_NONE],
        'authorized' => ['state' => self::STATE_PENDING_PAYMENT, 'action' => self::ACTION_NONE],
        'pending' => ['state' => self::STATE_PENDING_PAYMENT, 'action' => self::ACTION_NONE],
        'pending_payment' => ['state' => self::STATE_PENDING_PAYMENT, 'action' => self::ACTION_NONE],
        'waiting' => ['state' => self::STATE_PENDING_PAYMENT, 'action' => self::ACTION_NONE],

        'failed' => ['state' => self::STATE_CANCELED, 'action' => self::ACTION_CANCEL],
        'expired' => ['state' => self::STATE_CANCELED, 'action' => self::ACTION_CANCEL],
        'denied' => ['state' => self::STATE_CANCELED, 'action' => self::ACTION_CANCEL],
        'cancel' => ['state' => self::STATE_CANCELED, 'action' => self::ACTION_CANCEL],
        'cancelled' => ['state' => self::STATE_CANCELED, 'action' => self::ACTION_CANCEL],
        'canceled' => ['state' => self::STATE_CANCELED, 'action' => self::ACTION_CANCEL],

        'hold' => ['state' => self::STATE_HOLDED, 'action' => self::ACTION_NONE],
        'on_hold' => ['state' => self::STATE_HOLDED, 'action' => self::ACTION_NONE],
    ];

    /**
     * @return array{state:string,action:string,status:?string}
     *     `state` is always one of the {@see self::STATE_*} constants (never null);
     *     `action` is one of the {@see self::ACTION_*} constants;
     *     `status` is the human status label to set on the order, or null to
     *     let Magento derive it from the state (recommended).
     */
    public static function resolve(string $gatewayStatus): array
    {
        $key = strtolower(trim($gatewayStatus));
        if (isset(self::MAP[$key])) {
            return ['state' => self::MAP[$key]['state'], 'action' => self::MAP[$key]['action'], 'status' => null];
        }
        // Unknown status: do not mutate the order's state. Still return a
        // (no-op) descriptor so the caller can persist the log row.
        return ['state' => self::STATE_HOLDED, 'action' => self::ACTION_NONE, 'status' => null];
    }

    public static function isKnown(string $gatewayStatus): bool
    {
        return array_key_exists(strtolower(trim($gatewayStatus)), self::MAP);
    }
}
