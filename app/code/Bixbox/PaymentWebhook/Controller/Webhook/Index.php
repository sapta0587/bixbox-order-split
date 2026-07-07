<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Frontend controller for POST /bixbox/webhook.
 *
 * Reads the raw request body (the gateway's dynamic JSON, accepted verbatim),
 * checks the shared-secret header + the master switch, delegates to
 * {@see \Bixbox\PaymentWebhook\Model\WebhookProcessor}, and returns a JSON
 * response. CSRF is disabled (gateways cannot send a Magento form key) via
 * CsrfAwareActionInterface. The HttpPostActionInterface contract restricts
 * the action to POST (GET yields a 404 from the front controller).
 *
 * HTTP status codes:
 *   200  processed (the `idempotent` flag in the body says whether it was a replay)
 *   400  module disabled / invalid JSON / missing payment_id / unknown status
 *   401  missing or mismatched X-Bixbox-Webhook-Token
 *   404  no order carries the given payment_id
 *   500  order state transition threw
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Controller\Webhook;

use Bixbox\PaymentWebhook\Model\Config;
use Bixbox\PaymentWebhook\Model\WebhookProcessor;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Webapi\Exception as WebapiException;

class Index implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly Context $context,
        private readonly Config $config,
        private readonly WebhookProcessor $webhookProcessor,
        private readonly JsonFactory $jsonFactory
    ) {
    }

    public function execute()
    {
        $json = $this->jsonFactory->create();
        $json->setHeader('Content-Type', 'application/json');

        try {
            if (!$this->config->isEnabled()) {
                return $this->error($json, 400, 'Payment webhook endpoint is disabled.');
            }

            $secret = $this->config->getSharedSecret();
            $token = (string) $this->context->getRequest()->getHeader(Config::HEADER_WEBHOOK_TOKEN);
            if ($secret === '' || !hash_equals($secret, $token)) {
                return $this->error($json, 401, 'Invalid or missing webhook token.');
            }

            $raw = (string) $this->context->getRequest()->getContent();
            $payload = json_decode($raw, true);
            if (!is_array($payload)) {
                return $this->error($json, 400, 'Request body must be a JSON object.');
            }

            $result = $this->webhookProcessor->process($payload);
            $json->setHttpResponseCode(200);
            $json->setData($result);
            return $json;
        } catch (WebapiException $e) {
            return $this->error($json, (int) $e->getHttpCode(), $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($json, 500, 'Internal webhook error.');
        }
    }

    /**
     * CSRF bypass: gateways cannot send a Magento form key.
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): bool
    {
        return true;
    }

    private function error(Json $json, int $code, string $message): Json
    {
        $json->setHttpResponseCode($code);
        $json->setData(['message' => $message]);
        return $json;
    }
}
