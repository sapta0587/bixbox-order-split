<?php
/**
 * Bixbox_OrderSplit
 *
 * Unit tests for the pure, dependency-free parts of {@see OrderSplitter}
 * (buildKey + computeGroups). These run without a Magento bootstrap because
 * the methods under test touch no Magento types.
 */

declare(strict_types=1);

namespace Bixbox\OrderSplit\Test\Unit\Model;

use Bixbox\OrderSplit\Model\OrderSplitter;
use PHPUnit\Framework\TestCase;

class OrderSplitterTest extends TestCase
{
    /**
     * @return array<string,array{0:array,1:string[],2:string}>
     */
    public static function buildKeyDataProvider(): array
    {
        return [
            // all dimensions, all values present
            'all dims, all values' => [
                ['item_id' => 1, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
                ['category', 'vendor', 'warehouse'],
                'category=4|vendor=Vendor A|warehouse=Warehouse North',
            ],
            // only vendor dimension enabled
            'only vendor' => [
                ['item_id' => 1, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
                ['vendor'],
                'vendor=Vendor A',
            ],
            // dimensions supplied out of order -> key is still deterministic
            'dims out of order' => [
                ['item_id' => 1, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
                ['warehouse', 'category', 'vendor'],
                'category=4|vendor=Vendor A|warehouse=Warehouse North',
            ],
            // no dimensions enabled -> empty key (single group, no split)
            'no dims' => [
                ['item_id' => 1, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
                [],
                '',
            ],
            // null/missing values normalise to empty string
            'null values' => [
                ['item_id' => 1, 'category' => null, 'vendor' => null, 'warehouse' => null],
                ['category', 'vendor', 'warehouse'],
                'category=|vendor=|warehouse=',
            ],
            'missing keys' => [
                ['item_id' => 1],
                ['category', 'vendor', 'warehouse'],
                'category=|vendor=|warehouse=',
            ],
            // integer category is cast to string
            'int category' => [
                ['item_id' => 1, 'category' => 12, 'vendor' => 'Vendor B', 'warehouse' => 'Warehouse South'],
                ['category'],
                'category=12',
            ],
        ];
    }

    /**
     * @dataProvider buildKeyDataProvider
     * @param array  $descriptor
     * @param string[] $dimensions
     * @param string $expected
     */
    public function testBuildKey(array $descriptor, array $dimensions, string $expected): void
    {
        $this->assertSame($expected, OrderSplitter::buildKey($descriptor, $dimensions));
    }

    public function testComputeGroupsSingleGroup(): void
    {
        $descriptors = [
            ['item_id' => 10, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
            ['item_id' => 11, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
            ['item_id' => 12, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
        ];

        $groups = OrderSplitter::computeGroups($descriptors, ['category', 'vendor', 'warehouse']);

        $this->assertCount(1, $groups, 'All items share a key -> one group');
        $key = 'category=4|vendor=Vendor A|warehouse=Warehouse North';
        $this->assertArrayHasKey($key, $groups);
        $this->assertSame([10, 11, 12], $groups[$key]);
    }

    public function testComputeGroupsSplitsByVendor(): void
    {
        $descriptors = [
            ['item_id' => 10, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
            ['item_id' => 11, 'category' => 4, 'vendor' => 'Vendor B', 'warehouse' => 'Warehouse North'],
            ['item_id' => 12, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
        ];

        $groups = OrderSplitter::computeGroups($descriptors, ['category', 'vendor', 'warehouse']);

        $this->assertCount(2, $groups, 'Two distinct vendors -> two groups');
        $aKey = 'category=4|vendor=Vendor A|warehouse=Warehouse North';
        $bKey = 'category=4|vendor=Vendor B|warehouse=Warehouse North';
        $this->assertArrayHasKey($aKey, $groups);
        $this->assertArrayHasKey($bKey, $groups);
        $this->assertSame([10, 12], $groups[$aKey]);
        $this->assertSame([11], $groups[$bKey]);
    }

    public function testComputeGroupsRespectsEnabledDimensions(): void
    {
        // Items differ ONLY by category. With category disabled they collapse
        // into a single group; with it enabled they split into two.
        $descriptors = [
            ['item_id' => 10, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
            ['item_id' => 11, 'category' => 5, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
        ];

        $noCategory = OrderSplitter::computeGroups($descriptors, ['vendor', 'warehouse']);
        $this->assertCount(1, $noCategory, 'Category disabled -> items with same vendor/warehouse group together');

        $withCategory = OrderSplitter::computeGroups($descriptors, ['category', 'vendor', 'warehouse']);
        $this->assertCount(2, $withCategory, 'Category enabled -> different categories split');
    }

    public function testComputeGroupsItemsWithoutValuesStillGroup(): void
    {
        // Two products with no vendor/warehouse assigned should land in the same
        // group (both empty), rather than being scattered.
        $descriptors = [
            ['item_id' => 10, 'category' => null, 'vendor' => null, 'warehouse' => null],
            ['item_id' => 11, 'category' => null, 'vendor' => null, 'warehouse' => null],
        ];

        $groups = OrderSplitter::computeGroups($descriptors, ['category', 'vendor', 'warehouse']);

        $this->assertCount(1, $groups);
        $this->assertSame([10, 11], reset($groups));
    }

    public function testComputeGroupsEmptyInput(): void
    {
        $this->assertSame([], OrderSplitter::computeGroups([], ['category', 'vendor', 'warehouse']));
    }

    public function testComputeGroupsPreservesItemOrderWithinGroup(): void
    {
        $descriptors = [
            ['item_id' => 30, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
            ['item_id' => 10, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
            ['item_id' => 20, 'category' => 4, 'vendor' => 'Vendor A', 'warehouse' => 'Warehouse North'],
        ];

        $groups = OrderSplitter::computeGroups($descriptors, ['vendor']);

        $this->assertSame([30, 10, 20], reset($groups), 'Item order within a group is preserved');
    }
}
