<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Unit tests for the pure IdempotencyKey. Runs without a Magento bootstrap.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Test\Unit\Model;

use Bixbox\PaymentWebhook\Model\IdempotencyKey;
use PHPUnit\Framework\TestCase;

class IdempotencyKeyTest extends TestCase
{
    public function testHashIs64CharHex(): void
    {
        $h = IdempotencyKey::hash(['a' => 1]);
        self::assertSame(64, strlen($h));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $h);
    }

    public function testIdenticalPayloadsCollide(): void
    {
        $a = IdempotencyKey::hash(['payment_id' => 'p1', 'payment_detail' => ['status' => 'paid']]);
        $b = IdempotencyKey::hash(['payment_id' => 'p1', 'payment_detail' => ['status' => 'paid']]);
        self::assertSame($a, $b);
    }

    public function testKeyOrderDoesNotAffectHash(): void
    {
        $a = IdempotencyKey::hash(['b' => 2, 'a' => 1, 'c' => 3]);
        $b = IdempotencyKey::hash(['c' => 3, 'a' => 1, 'b' => 2]);
        self::assertSame($a, $b);
    }

    public function testNestedKeyOrderDoesNotAffectHash(): void
    {
        $a = IdempotencyKey::hash(['payment_detail' => ['status' => 'paid', 'va_code' => 'xx001'], 'payment_id' => 'p1']);
        $b = IdempotencyKey::hash(['payment_id' => 'p1', 'payment_detail' => ['va_code' => 'xx001', 'status' => 'paid']]);
        self::assertSame($a, $b);
    }

    public function testDifferentPayloadsDoNotCollide(): void
    {
        $a = IdempotencyKey::hash(['payment_id' => 'p1', 'payment_detail' => ['status' => 'paid']]);
        $b = IdempotencyKey::hash(['payment_id' => 'p1', 'payment_detail' => ['status' => 'authorize']]);
        self::assertNotSame($a, $b);
    }

    public function testReplayOfSameVariationOneCollides(): void
    {
        $body = ['payment_id' => '123xx', 'payment_detail' => ['status' => 'paid', 'va_code' => 'xx001']];
        self::assertSame(IdempotencyKey::hash($body), IdempotencyKey::hash($body));
    }

    public function testListOrderMatters(): void
    {
        $a = IdempotencyKey::hash(['items' => [['sku' => 'a'], ['sku' => 'b']]]);
        $b = IdempotencyKey::hash(['items' => [['sku' => 'b'], ['sku' => 'a']]]);
        self::assertNotSame($a, $b, 'list element order is significant');
    }

    public function testScalarPayloadHashesStably(): void
    {
        self::assertSame(IdempotencyKey::hash('x'), IdempotencyKey::hash('x'));
        self::assertSame(IdempotencyKey::hash(42), IdempotencyKey::hash(42));
        self::assertNotSame(IdempotencyKey::hash('x'), IdempotencyKey::hash(42));
    }

    public function testNonArrayPayloadHashesStably(): void
    {
        self::assertSame(IdempotencyKey::hash(null), IdempotencyKey::hash(null));
        self::assertSame(IdempotencyKey::hash(true), IdempotencyKey::hash(true));
    }

    public function testCanonicalizeProducesSortedJson(): void
    {
        $c = IdempotencyKey::canonicalize(['b' => 2, 'a' => 1]);
        self::assertSame('{"a":1,"b":2}', $c);
    }

    public function testCanonicalizeHasUnescapedSlashes(): void
    {
        $c = IdempotencyKey::canonicalize(['email' => 'john.doe@example.com']);
        self::assertStringContainsString('john.doe@example.com', $c);
        self::assertStringNotContainsString('\\/', $c);
    }
}
