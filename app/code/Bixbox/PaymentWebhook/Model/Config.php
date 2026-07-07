<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Scope-config wrapper for the module's admin configuration. All flags live
 * under the `bixbox_paymentwebhook/*` config namespace.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'bixbox_paymentwebhook/general/enabled';
    public const XML_PATH_SHARED_SECRET = 'bixbox_paymentwebhook/security/shared_secret';

    public const HEADER_WEBHOOK_TOKEN = 'X-Bixbox-Webhook-Token';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The configured shared secret. An empty string means "fail-closed":
     * the manager refuses all webhooks (so a fresh install is never open).
     */
    public function getSharedSecret(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_SHARED_SECRET,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
