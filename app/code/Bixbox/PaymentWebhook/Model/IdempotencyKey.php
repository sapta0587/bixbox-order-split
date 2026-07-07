<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Pure, dependency-free idempotency key derivation.
 *
 * Two webhooks are treated as duplicates of each other iff they serialize to
 * the same canonical JSON: keys sorted recursively, associative arrays and
 * indexed lists normalised, booleans/integers/floats preserved as-is, no
 * escaped slashes. This makes exact replays collide while still letting
 * genuinely-different payloads (e.g. a later "paid" after an earlier
 * "authorize") through, because their content differs.
 *
 * Exposed as static so it is unit-testable without a Magento bootstrap.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

class IdempotencyKey
{
    /**
     * Returns the 64-char lowercase hex sha256 of the canonical JSON of the
     * payload. Returns the sha256 of an empty-object canonical form for
     * non-array payloads so the hash is always a stable 64-char string.
     */
    public static function hash(mixed $payload): string
    {
        return hash('sha256', self::canonicalize($payload));
    }

    /**
     * Canonical, deterministic JSON serialization of any value.
     */
    public static function canonicalize(mixed $payload): string
    {
        return json_encode(
            self::canonicalizeValue($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (self::isList($value)) {
            $out = [];
            foreach (array_values($value) as $item) {
                $out[] = self::canonicalizeValue($item);
            }
            return $out;
        }

        // Associative array: sort keys recursively, then canonicalize each value.
        $out = [];
        foreach ($value as $k => $v) {
            $out[(string) $k] = self::canonicalizeValue($v);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * A "list" is a 0-indexed, contiguously-incremented array (i.e. what
     * json_decode produces for a JSON array). Anything else is treated as an
     * associative object.
     */
    private static function isList(array $array): bool
    {
        if ($array === []) {
            return true;
        }
        return array_keys($array) === range(0, count($array) - 1);
    }
}
