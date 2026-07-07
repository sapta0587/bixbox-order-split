<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Repository contract for the idempotency log. Used by WebhookProcessor to
 * look up an existing row by payload_hash (the dedupe check) and to persist
 * a new row after processing.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Api;

use Bixbox\PaymentWebhook\Api\Data\WebhookLogInterface;

interface WebhookLogRepositoryInterface
{
    public function getByPayloadHash(string $payloadHash): ?WebhookLogInterface;

    public function save(WebhookLogInterface $log): WebhookLogInterface;
}
