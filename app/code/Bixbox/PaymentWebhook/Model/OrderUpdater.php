<?php
/**
 * Bixbox_PaymentWebhook
 *
 * Applies the state transition computed by {@see StatusMapper} to a Magento
 * order. Three actions:
 *
 *   - ACTION_INVOICE:  create an online invoice for the full order, mark it
 *                      paid, and move the order to STATE_PROCESSING.
 *   - ACTION_CANCEL:   cancel the order (restocks items, voids the payment)
 *                      and move it to STATE_CANCELED.
 *   - ACTION_NONE:     only set state + status, leave items / payment alone.
 *
 * The order is loaded fresh from the repository (not the caller's instance)
 * so the writer always sees current DB state. State transitions are guarded:
 * an order already in STATE_COMPLETE / STATE_CANCELED is never re-invoiced or
 * re-cancelled by a replay (the idempotency log is the first line of defence,
 * but this is the second).
 */

declare(strict_types=1);

namespace Bixbox\PaymentWebhook\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\DB\TransactionFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Sales\Model\Order\Invoice;

class OrderUpdater
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceService $invoiceService,
        private readonly TransactionFactory $transactionFactory
    ) {
    }

    /**
     * @param int    $orderId
     * @param string $targetState  One of StatusMapper::STATE_*.
     * @param string $action       One of StatusMapper::ACTION_*.
     * @return array{state:string,status:string,action:string}
     * @throws LocalizedException  when the order can't transition.
     */
    public function update(int $orderId, string $targetState, string $action): array
    {
        $order = $this->orderRepository->get($orderId);
        $currentState = (string) $order->getState();

        $appliedAction = $action;
        if ($this->isTerminal($currentState)) {
            $appliedAction = StatusMapper::ACTION_NONE;
        }

        switch ($appliedAction) {
            case StatusMapper::ACTION_INVOICE:
                $this->invoiceOrder($order, $targetState);
                break;
            case StatusMapper::ACTION_CANCEL:
                $this->cancelOrder($order);
                break;
            case StatusMapper::ACTION_NONE:
            default:
                $this->setOrderState($order, $targetState);
                break;
        }

        return [
            'state' => (string) $order->getState(),
            'status' => (string) $order->getStatus(),
            'action' => $appliedAction,
        ];
    }

    private function isTerminal(string $state): bool
    {
        return in_array($state, [StatusMapper::STATE_COMPLETE, StatusMapper::STATE_CANCELED], true);
    }

    private function setOrderState(Order $order, string $state): void
    {
        // setState() with a null status auto-derives the default status label
        // for the given state via Order\Config::getStateDefaultStatus().
        $order->setState($state);
        $this->orderRepository->save($order);
    }

    private function invoiceOrder(Order $order, string $targetState): void
    {
        if ($order->canInvoice()) {
            $invoice = $this->invoiceService->prepareInvoice($order);
            $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
            $invoice->register();
            $order->setIsInProcess(true);

            $transaction = $this->transactionFactory->create();
            $transaction->addObject($invoice)->addObject($order)->save();
        } else {
            $order->setState($targetState);
            $this->orderRepository->save($order);
        }
    }

    private function cancelOrder(Order $order): void
    {
        if ($order->canCancel()) {
            $order->cancel();
            $this->orderRepository->save($order);
        } else {
            $this->setOrderState($order, StatusMapper::STATE_CANCELED);
        }
    }
}
