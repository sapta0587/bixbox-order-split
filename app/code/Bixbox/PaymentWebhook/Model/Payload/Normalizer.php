<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Pure, dependency-free normalizer that turns a *dynamic* gateway payload
 * (any shape of array) into a typed {@see WebhookPayload}. Nothing here ever
 * throws on missing or malformed data; it just yields nulls / empty arrays
 * for whatever it couldn't find, so the caller can validate explicitly.
 *
 * Handles the three brief variations (and arbitrary others) uniformly:
 *
 *   {"payment_id":"123xx","payment_detail":{"status":"paid","va_code":"xx001"}}
 *   {"payment_id":"123xx","payment_detail":{"status":"paid","qr_code":"xx001"},"items":[{"sku":"sku1","qty":100}]}
 *   {"payment_id":"123xx","payment_detail":{"status":"authorize"},"customer":{"email":"john.doe@example.com"}}
 *
 * Exposed as static so it is unit-testable without a Magento bootstrap.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model\Payload;

class Normalizer
{
    /**
     * Top-level payment id field name as sent by every brief variation.
     */
    public const FIELD_PAYMENT_ID = 'payment_id';

    /**
     * Nested object that carries status + optional va_code / qr_code.
     */
    public const FIELD_PAYMENT_DETAIL = 'payment_detail';

    public const FIELD_STATUS = 'status';
    public const FIELD_VA_CODE = 'va_code';
    public const FIELD_QR_CODE = 'qr_code';

    public const FIELD_ITEMS = 'items';
    public const FIELD_CUSTOMER = 'customer';
    public const FIELD_CUSTOMER_EMAIL = 'email';

    /**
     * @param array|mixed $payload  Decoded JSON body. Anything non-array yields an empty payload.
     */
    public static function normalize(mixed $payload): WebhookPayload
    {
        if (!is_array($payload)) {
            return new WebhookPayload(null, null, null, null, [], null);
        }

        return new WebhookPayload(
            self::extractString($payload, self::FIELD_PAYMENT_ID),
            self::extractStatus($payload),
            self::extractString(self::extractArray($payload, self::FIELD_PAYMENT_DETAIL), self::FIELD_VA_CODE),
            self::extractString(self::extractArray($payload, self::FIELD_PAYMENT_DETAIL), self::FIELD_QR_CODE),
            self::extractItems($payload),
            self::extractString(self::extractArray($payload, self::FIELD_CUSTOMER), self::FIELD_CUSTOMER_EMAIL)
        );
    }

    private static function extractString(array $array, string $key): ?string
    {
        if (!isset($array[$key])) {
            return null;
        }
        $value = $array[$key];
        if (is_bool($value) || is_array($value)) {
            return null;
        }
        $value = is_scalar($value) ? (string) $value : '';
        return $value === '' ? null : $value;
    }

    private static function extractArray(array $array, string $key): array
    {
        return isset($array[$key]) && is_array($array[$key]) ? $array[$key] : [];
    }

    private static function extractStatus(array $payload): ?string
    {
        $detail = self::extractArray($payload, self::FIELD_PAYMENT_DETAIL);
        $status = self::extractString($detail, self::FIELD_STATUS);
        if ($status === null) {
            return null;
        }
        $normalized = strtolower(trim($status));
        return $normalized === '' ? null : $normalized;
    }

    /**
     * Best-effort: keep items[] exactly as received (list of arrays). Non-list
     * values (e.g. an object-as-map) are coerced to an empty list so the
     * downstream code can always iterate.
     */
    private static function extractItems(array $payload): array
    {
        $items = $payload[self::FIELD_ITEMS] ?? null;
        if (!is_array($items)) {
            return [];
        }
        // Re-index to a 0-based list so callers don't depend on gateway-chosen keys.
        return array_values(array_filter($items, fn ($i) => is_array($i)));
    }
}
