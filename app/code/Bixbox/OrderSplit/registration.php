<?php
/**
 * Bixbox_OrderSplit registration.
 *
 * Registers the module with Magento's component registrar so it can be
 * enabled via `bin/magento module:enable Bixbox_OrderSplit`.
 */

\Magento\Framework\Component\ComponentRegistrar::register(
    \Magento\Framework\Component\ComponentRegistrar::MODULE,
    'Bixbox_OrderSplit',
    __DIR__
);
