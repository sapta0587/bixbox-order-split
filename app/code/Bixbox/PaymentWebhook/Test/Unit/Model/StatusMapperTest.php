<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Unit tests for the pure StatusMapper. Runs without a Magento bootstrap.
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Test\Unit\Model;

use Bixbox\PaymentWebhook\Model\StatusMapper;
use PHPUnit\Framework\TestCase;

class StatusMapperTest extends TestCase
{
    public function testPaidInvoicesAndMovesToProcessing(): void
    {
        $r = StatusMapper::resolve('paid');
        self::assertSame(StatusMapper::STATE_PROCESSING, $r['state']);
        self::assertSame(StatusMapper::ACTION_INVOICE, $r['action']);
        self::assertNull($r['status']);
        self::assertTrue(StatusMapper::isKnown('paid'));
    }

    public function testPaidIsCaseInsensitiveAndTrimmed(): void
    {
        $r = StatusMapper::resolve('  PAID  ');
        self::assertSame(StatusMapper::STATE_PROCESSING, $r['state']);
        self::assertSame(StatusMapper::ACTION_INVOICE, $r['action']);
    }

    public function testCaptureAliasesMapToInvoice(): void
    {
        foreach (['capture', 'captured', 'success', 'succeeded', 'settlement'] as $s) {
            $r = StatusMapper::resolve($s);
            self::assertSame(StatusMapper::STATE_PROCESSING, $r['state'], $s);
            self::assertSame(StatusMapper::ACTION_INVOICE, $r['action'], $s);
            self::assertTrue(StatusMapper::isKnown($s), $s);
        }
    }

    public function testAuthorizeMapsToPendingPaymentNoAction(): void
    {
        foreach (['authorize', 'authorized', 'pending', 'pending_payment', 'waiting'] as $s) {
            $r = StatusMapper::resolve($s);
            self::assertSame(StatusMapper::STATE_PENDING_PAYMENT, $r['state'], $s);
            self::assertSame(StatusMapper::ACTION_NONE, $r['action'], $s);
        }
    }

    public function testFailedAndCancelledMapToCancel(): void
    {
        foreach (['failed', 'expired', 'denied', 'cancel', 'cancelled', 'canceled'] as $s) {
            $r = StatusMapper::resolve($s);
            self::assertSame(StatusMapper::STATE_CANCELED, $r['state'], $s);
            self::assertSame(StatusMapper::ACTION_CANCEL, $r['action'], $s);
        }
    }

    public function testHoldMapsToHoldedNoAction(): void
    {
        foreach (['hold', 'on_hold'] as $s) {
            $r = StatusMapper::resolve($s);
            self::assertSame(StatusMapper::STATE_HOLDED, $r['state'], $s);
            self::assertSame(StatusMapper::ACTION_NONE, $r['action'], $s);
        }
    }

    public function testUnknownStatusYieldsNoOpAndIsNotKnown(): void
    {
        $r = StatusMapper::resolve('totally-bogus-status');
        self::assertSame(StatusMapper::ACTION_NONE, $r['action']);
        self::assertFalse(StatusMapper::isKnown('totally-bogus-status'));
    }

    public function testResolveAlwaysReturnsAStateAndAction(): void
    {
        $r = StatusMapper::resolve('');
        self::assertNotEmpty($r['state']);
        self::assertNotEmpty($r['action']);
    }
}
