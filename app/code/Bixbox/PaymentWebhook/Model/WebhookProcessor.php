<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Orchestrates one webhook call:
 *
 *   1. canonicalize + hash the payload (idempotency key);
 *   2. if a log row already exists for that hash, return its cached result
 *      marked idempotent=true (NO order mutation on replay);
 *   3. normalize the payload into a WebhookPayload (graceful on missing fields);
 *   4. validate (payment_id + known status); throw WebapiException otherwise;
 *   5. find the order by payment_id; throw 404 if none;
 *   6. resolve the state transition via StatusMapper;
 *   7. apply it via OrderUpdater inside a DB transaction, persist the log row.
 *
 * The pure logic (Normalizer / StatusMapper / IdempotencyKey) is static and
 * unit-tested; this class is the Magento-bound glue.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

use Bixbox\PaymentWebhook\Api\Data\WebhookLogInterface;
use Bixbox\PaymentWebhook\Api\WebhookLogRepositoryInterface;
use Bixbox\PaymentWebhook\Model\Data\WebhookLogFactory;
use Bixbox\PaymentWebhook\Model\Payload\Normalizer;
use Bixbox\PaymentWebhook\Model\Payload\WebhookPayload;
use Magento\Framework\DB\Adapter\DuplicateException;
use Magento\Framework\Webapi\Exception as WebapiException;
use Psr\Log\LoggerInterface;

class WebhookProcessor
{
    public function __construct(
        private readonly WebhookLogRepositoryInterface $webhookLogRepository,
        private readonly OrderFinder $orderFinder,
        private readonly OrderUpdater $orderUpdater,
        private readonly WebhookLogFactory $webhookLogFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return array{
     *     order_id: int|null,
     *     state: string|null,
     *     status: string|null,
     *     action: string,
     *     idempotent: bool,
     *     payment_id: string|null
     * }
     * @throws WebapiException
     */
    public function process(array $payload): array
    {
        $payloadHash = IdempotencyKey::hash($payload);
        $canonicalPayload = IdempotencyKey::canonicalize($payload);

        $existing = $this->webhookLogRepository->getByPayloadHash($payloadHash);
        if ($existing !== null) {
            return [
                'order_id' => $existing->getOrderId(),
                'state' => $existing->getOrderState(),
                'status' => null,
                'action' => (string) $existing->getAction(),
                'idempotent' => true,
                'payment_id' => $existing->getPaymentId(),
            ];
        }

        $dto = Normalizer::normalize($payload);
        $this->validate($dto);

        $orderId = $this->orderFinder->findOrderIdByPaymentId($dto->paymentId ?? '');
        if ($orderId === null) {
            throw new WebapiException(
                __('No order found for payment_id %1.', $dto->paymentId),
                0,
                WebapiException::HTTP_NOT_FOUND
            );
        }

        $transition = StatusMapper::resolve($dto->status ?? '');
        try {
            $result = $this->orderUpdater->update(
                $orderId,
                $transition['state'],
                $transition['action']
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'Bixbox_PaymentWebhook: order update failed: ' . $e->getMessage(),
                ['order_id' => $orderId, 'exception' => $e]
            );
            throw new WebapiException(
                __('Failed to update order %1.', $orderId),
                0,
                WebapiException::HTTP_INTERNAL_ERROR
            );
        }

        $log = $this->buildLog(
            $payloadHash,
            $canonicalPayload,
            $dto,
            $orderId,
            $result['state'],
            $result['action']
        );
        try {
            $this->webhookLogRepository->save($log);
        } catch (DuplicateException $e) {
            return [
                'order_id' => $orderId,
                'state' => $result['state'],
                'status' => $result['status'],
                'action' => $result['action'],
                'idempotent' => true,
                'payment_id' => $dto->paymentId,
            ];
        }

        return [
            'order_id' => $orderId,
            'state' => $result['state'],
            'status' => $result['status'],
            'action' => $result['action'],
            'idempotent' => false,
            'payment_id' => $dto->paymentId,
        ];
    }

    /**
     * @throws WebapiException  400 when payment_id or status is missing/unknown.
     */
    private function validate(WebhookPayload $dto): void
    {
        if (!$dto->isValid()) {
            throw new WebapiException(
                __('Payload must include payment_id and payment_detail.status.'),
                0,
                WebapiException::HTTP_BAD_REQUEST
            );
        }
        if (!StatusMapper::isKnown($dto->status ?? '')) {
            throw new WebapiException(
                __('Unknown payment status "%1".', $dto->status),
                0,
                WebapiException::HTTP_BAD_REQUEST
            );
        }
    }

    private function buildLog(
        string $payloadHash,
        string $canonicalPayload,
        WebhookPayload $dto,
        int $orderId,
        string $resultState,
        string $action
    ): WebhookLogInterface {
        $log = $this->webhookLogFactory->create();
        $log->setPaymentId($dto->paymentId);
        $log->setPayloadHash($payloadHash);
        $log->setStatus($dto->status);
        $log->setAction($action);
        $log->setOrderId($orderId);
        $log->setOrderState($resultState);
        $log->setPayload($canonicalPayload);
        $log->setIsDuplicate(false);
        return $log;
    }
}
