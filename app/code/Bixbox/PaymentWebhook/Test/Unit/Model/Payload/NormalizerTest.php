<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Unit tests for the pure payload Normalizer. Runs without a Magento
 * bootstrap (the class under test has no Magento dependencies).
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Test\Unit\Model\Payload;

use Bixbox\PaymentWebhook\Model\Payload\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    public function testVariationOneWithVaCode(): void
    {
        $payload = [
            'payment_id' => '123xx',
            'payment_detail' => ['status' => 'paid', 'va_code' => 'xx001'],
        ];

        $dto = Normalizer::normalize($payload);

        self::assertSame('123xx', $dto->paymentId);
        self::assertSame('paid', $dto->status);
        self::assertSame('xx001', $dto->vaCode);
        self::assertNull($dto->qrCode);
        self::assertSame([], $dto->items);
        self::assertNull($dto->customerEmail);
        self::assertTrue($dto->isValid());
    }

    public function testVariationTwoWithQrCodeAndItems(): void
    {
        $payload = [
            'payment_id' => '123xx',
            'payment_detail' => ['status' => 'paid', 'qr_code' => 'xx001'],
            'items' => [['sku' => 'sku1', 'qty' => 100]],
        ];

        $dto = Normalizer::normalize($payload);

        self::assertSame('123xx', $dto->paymentId);
        self::assertSame('paid', $dto->status);
        self::assertNull($dto->vaCode);
        self::assertSame('xx001', $dto->qrCode);
        self::assertCount(1, $dto->items);
        self::assertSame(['sku' => 'sku1', 'qty' => 100], $dto->items[0]);
        self::assertNull($dto->customerEmail);
        self::assertTrue($dto->isValid());
    }

    public function testVariationThreeWithCustomerEmail(): void
    {
        $payload = [
            'payment_id' => '123xx',
            'payment_detail' => ['status' => 'authorize'],
            'customer' => ['email' => 'john.doe@example.com'],
        ];

        $dto = Normalizer::normalize($payload);

        self::assertSame('123xx', $dto->paymentId);
        self::assertSame('authorize', $dto->status);
        self::assertNull($dto->vaCode);
        self::assertNull($dto->qrCode);
        self::assertSame([], $dto->items);
        self::assertSame('john.doe@example.com', $dto->customerEmail);
        self::assertTrue($dto->isValid());
    }

    public function testStatusIsLowercased(): void
    {
        $dto = Normalizer::normalize([
            'payment_id' => 'p1',
            'payment_detail' => ['status' => '  PAID  '],
        ]);
        self::assertSame('paid', $dto->status);
    }

    public function testMissingPaymentIdIsInvalid(): void
    {
        $dto = Normalizer::normalize(['payment_detail' => ['status' => 'paid']]);
        self::assertNull($dto->paymentId);
        self::assertFalse($dto->isValid());
    }

    public function testMissingStatusIsInvalid(): void
    {
        $dto = Normalizer::normalize(['payment_id' => 'p1', 'payment_detail' => []]);
        self::assertNull($dto->status);
        self::assertFalse($dto->isValid());
    }

    public function testMissingPaymentDetailObjectYieldsNullStatus(): void
    {
        $dto = Normalizer::normalize(['payment_id' => 'p1']);
        self::assertSame('p1', $dto->paymentId);
        self::assertNull($dto->status);
        self::assertNull($dto->vaCode);
        self::assertNull($dto->qrCode);
        self::assertFalse($dto->isValid());
    }

    public function testEmptyPaymentIdIsInvalid(): void
    {
        $dto = Normalizer::normalize([
            'payment_id' => '',
            'payment_detail' => ['status' => 'paid'],
        ]);
        self::assertNull($dto->paymentId);
        self::assertFalse($dto->isValid());
    }

    public function testNonArrayPayloadYieldsEmptyDto(): void
    {
        $dto = Normalizer::normalize('not-an-array');
        self::assertNull($dto->paymentId);
        self::assertNull($dto->status);
        self::assertSame([], $dto->items);
        self::assertFalse($dto->isValid());
    }

    public function testItemsNonListValueCoercedToEmpty(): void
    {
        $dto = Normalizer::normalize([
            'payment_id' => 'p1',
            'payment_detail' => ['status' => 'paid'],
            'items' => 'not-a-list',
        ]);
        self::assertSame([], $dto->items);
    }

    public function testItemsFilteredToArraysOnly(): void
    {
        $dto = Normalizer::normalize([
            'payment_id' => 'p1',
            'payment_detail' => ['status' => 'paid'],
            'items' => [['sku' => 'a'], 'stray-string', 42, ['sku' => 'b']],
        ]);
        self::assertCount(2, $dto->items);
        self::assertSame('a', $dto->items[0]['sku']);
        self::assertSame('b', $dto->items[1]['sku']);
    }

    public function testBooleanPaymentIdIsCoercedToNull(): void
    {
        $dto = Normalizer::normalize([
            'payment_id' => true,
            'payment_detail' => ['status' => 'paid'],
        ]);
        self::assertNull($dto->paymentId);
        self::assertFalse($dto->isValid());
    }

    public function testNumericPaymentIdIsStringified(): void
    {
        $dto = Normalizer::normalize([
            'payment_id' => 12345,
            'payment_detail' => ['status' => 'paid'],
        ]);
        self::assertSame('12345', $dto->paymentId);
        self::assertTrue($dto->isValid());
    }
}
