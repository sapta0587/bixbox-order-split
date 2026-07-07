<?php
/**
 * Bixbox_PaymentWebhook registration.
 *
 * Registers the module with Magento's component registrar so it can be
 * enabled via `bin/magento module:enable Bixbox_PaymentWebhook`.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Bixbox_PaymentWebhook',
    __DIR__
);
