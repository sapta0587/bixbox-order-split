<?php
/**
 * Bixbox_OrderSplit
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT License (MIT)
 *
 * @package      Bixbox_OrderSplit
 * @copyright    Copyright (c) Bixbox
 * @license      MIT License (MIT)
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Setup\Patch\Data;

use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Adds the two custom product attributes required by the Bixbox split logic:
 *
 *   - vendor           (select, admin-managed options)  -> the supplying vendor
 *   - warehouse_area   (select, admin-managed options)  -> the origin warehouse area
 *
 * Both attributes are made visible on the storefront (PDP + PLP) and are
 * available to the quote/order splitting logic.
 *
 * A handful of clearly-labelled demo options are seeded so the module can be
 * verified out of the box; administrators are expected to replace them with
 * real vendor / warehouse values from Stores > Attributes > Product.
 */
class AddVendorAndWarehouseAttributes implements DataPatchInterface, PatchRevertableInterface
{
    /**
     * Attribute code for the supplying vendor.
     */
    public const VENDOR_ATTRIBUTE = 'vendor';

    /**
     * Attribute code for the origin warehouse area.
     */
    public const WAREHOUSE_AREA_ATTRIBUTE = 'warehouse_area';

    /**
     * Demo vendor options. Replace these with real vendors from the admin.
     */
    private array $demoVendors = ['Vendor A', 'Vendor B', 'Vendor C'];

    /**
     * Demo warehouse-area options. Replace these with real warehouses from the admin.
     */
    private array $demoWarehouses = ['Warehouse North', 'Warehouse South', 'Warehouse East'];

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory $eavSetupFactory
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly EavSetupFactory $eavSetupFactory
    ) {
    }

    /**
     * @inheritdoc
     */
    public function apply(): void
    {
        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        $commonConfig = [
            'type' => 'varchar',
            'input' => 'select',
            'required' => false,
            'visible' => true,
            'visible_on_front' => true,
            'used_in_product_listing' => true,
            'is_used_in_grid' => true,
            'is_visible_in_grid' => true,
            'is_filterable_in_grid' => true,
            'searchable' => true,
            'filterable' => false,
            'comparable' => true,
            'user_defined' => true,
            'global' => ScopedAttributeInterface::SCOPE_GLOBAL,
            'apply_to' => 'simple,configurable,virtual,bundle,downloadable,grouped',
            'group' => 'Product Details',
        ];

        // Vendor attribute (only create if it does not already exist). The
        // 'group' key above auto-assigns the attribute to the "Product Details"
        // group of every attribute set, so no manual group assignment is needed.
        if (!$eavSetup->getAttribute(Product::ENTITY, self::VENDOR_ATTRIBUTE)) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                self::VENDOR_ATTRIBUTE,
                array_merge($commonConfig, [
                    'label' => 'Vendor',
                    'option' => ['values' => $this->demoVendors],
                ])
            );
        }

        // Warehouse area attribute (only create if it does not already exist).
        if (!$eavSetup->getAttribute(Product::ENTITY, self::WAREHOUSE_AREA_ATTRIBUTE)) {
            $eavSetup->addAttribute(
                Product::ENTITY,
                self::WAREHOUSE_AREA_ATTRIBUTE,
                array_merge($commonConfig, [
                    'label' => 'Warehouse Area',
                    'option' => ['values' => $this->demoWarehouses],
                ])
            );
        }
    }

    /**
     * @inheritdoc
     */
    public function revert(): void
    {
        $this->moduleDataSetup->getConnection()->startSetup();

        /** @var EavSetup $eavSetup */
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);

        foreach ([self::VENDOR_ATTRIBUTE, self::WAREHOUSE_AREA_ATTRIBUTE] as $attributeCode) {
            $attribute = $eavSetup->getAttribute(Product::ENTITY, $attributeCode);
            if ($attribute) {
                $eavSetup->removeAttribute(Product::ENTITY, $attributeCode);
            }
        }

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @inheritdoc
     *
     * Returns no explicit dependencies: this patch is expected to run against an
     * already-installed Magento_Catalog (the default attribute set and the
     * "Product Details" group are created during the initial catalog install),
     * which is the normal deployment scenario for a distributable module.
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
