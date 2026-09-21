<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Service;

use Ced\VivaPayments\Model\ResourceModel\VivaPayments as VivaPaymentsResource;
use Ced\VivaPayments\Model\VivaPaymentsFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;
use Magento\Framework\DB\TransactionFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes payment callbacks from Viva Wallet.
 *
 * Handles order state transitions, capture registration, and email notifications.
 * Designed for idempotent processing to handle duplicate callbacks safely.
 */
class OrderProcessor
{
    private const CONFIG_PATH_ORDER_STATUS = 'payment/paymentmethod/order_status';

    private OrderFactory $orderFactory;
    private OrderRepositoryInterface $orderRepository;
    private OrderSender $orderSender;
    private InvoiceSender $invoiceSender;
    private InvoiceService $invoiceService;
    private TransactionFactory $transactionFactory;
    private VivaPaymentsFactory $vivaPaymentsFactory;
    private VivaPaymentsResource $vivaResource;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    public function __construct(
        OrderFactory $orderFactory,
        OrderRepositoryInterface $orderRepository,
        OrderSender $orderSender,
        InvoiceSender $invoiceSender,
        InvoiceService $invoiceService,
        TransactionFactory $transactionFactory,
        VivaPaymentsFactory $vivaPaymentsFactory,
        VivaPaymentsResource $vivaResource,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->orderFactory = $orderFactory;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->invoiceSender = $invoiceSender;
        $this->invoiceService = $invoiceService;
        $this->transactionFactory = $transactionFactory;
        $this->vivaPaymentsFactory = $vivaPaymentsFactory;
        $this->vivaResource = $vivaResource;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * Load an order by increment ID with null-safety.
     *
     * @throws NoSuchEntityException
     */
    public function loadOrder(string $incrementId): Order
    {
        if (empty($incrementId)) {
            throw new NoSuchEntityException(__('Order ID is missing.'));
        }

        /** @var Order $order */
        $order = $this->orderFactory->create()->loadByIncrementId($incrementId);

        if (!$order || !$order->getId()) {
            $this->logger->error('Order not found', ['increment_id' => $incrementId]);
            throw new NoSuchEntityException(
                __('Order with ID "%1" not found.', $incrementId)
            );
        }

        return $order;
    }

    /**
     * Load an order by Viva order code, using the vivapayments_data record as the link.
     * This avoids any dependency on the customer's checkout session.
     *
     * @throws NoSuchEntityException
     */
    public function loadOrderByVivaCode(string $vivaOrderCode): Order
    {
        if (empty($vivaOrderCode)) {
            throw new NoSuchEntityException(__('Viva order code is missing.'));
        }

        $vivaRecord = $this->vivaPaymentsFactory->create();
        $this->vivaResource->load($vivaRecord, $vivaOrderCode, 'ordercode');

        if (!$vivaRecord->getId()) {
            $this->logger->error('Viva payment record not found', ['order_code' => $vivaOrderCode]);
            throw new NoSuchEntityException(
                __('Payment record for order code "%1" not found.', $vivaOrderCode)
            );
        }

        return $this->loadOrder((string) $vivaRecord->getData('order_id'));
    }

    /**
     * Update the Viva payment record state.
     */
    public function updateVivaPaymentState(string $orderCode, string $state): void
    {
        try {
            $vivaRecord = $this->vivaPaymentsFactory->create();
            $this->vivaResource->load($vivaRecord, $orderCode, 'ordercode');
            if ($vivaRecord->getId()) {
                $vivaRecord->setData('order_state', $state);
                $this->vivaResource->save($vivaRecord);
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to update Viva payment record', [
                'order_code' => $orderCode,
                'state' => $state,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a successful payment callback.
     *
     * @param Order $order The Magento order
     * @param string $transactionId The Viva transaction ID
     * @param float $amount The captured amount
     * @param string $message Status message for order history
     */
    public function processSuccessfulPayment(
        Order $order,
        string $transactionId,
        float $amount,
        string $message
    ): void {
        // Idempotency check: skip if already processed
        if ($this->isAlreadyProcessed($order)) {
            $this->logger->info('Order already processed, skipping duplicate callback', [
                'order_id' => $order->getIncrementId(),
                'transaction_id' => $transactionId,
            ]);
            return;
        }

        $newStatus = $this->getConfiguredOrderStatus();

        $orderComment = sprintf(
            "Viva Wallet Smart Checkout Confirmed Transaction\nTxID: %s",
            $transactionId
        );

        $state = ($newStatus === Order::STATE_COMPLETE) ? Order::STATE_COMPLETE : Order::STATE_PROCESSING;
        $order->setState($state);
        $order->setStatus($newStatus);
        $order->setBaseTotalPaid($amount);
        $order->setTotalPaid($amount);

        $history = $order->addStatusHistoryComment($orderComment, false);
        $history->setIsCustomerNotified(true);

        $order->setCanSendNewEmailFlag(true);
        $order->setEmailSent(true);
        $this->orderRepository->save($order);

        $this->registerPaymentCapture($order, $transactionId, $amount, $message);

        try {
            $this->orderSender->send($order, true);
        } catch (\Exception $e) {
            $this->logger->warning('Failed to send order confirmation email', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a failed payment callback.
     *
     * @param Order $order The Magento order
     * @param string $message Cancellation reason
     */
    public function processFailedPayment(Order $order, string $message): void
    {
        try {
            if ($order->getId() && $order->getState() !== Order::STATE_CANCELED) {
                // Add status comment instead of cancelling order to allow retries with the same Viva OrderCode.
                // Magento order lifetime cron will handle cleaning up unpaid abandoned orders.
                $order->addStatusHistoryComment(
                    __('Viva Wallet Payment failed or was cancelled by the customer. Reason: %1', $message)
                );
                $this->orderRepository->save($order);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to update order on failed payment', [
                'order_id' => $order->getIncrementId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Register a payment capture notification on the order.
     */
    private function registerPaymentCapture(
        Order $order,
        string $transactionId,
        float $amount,
        string $message
    ): void {
        $payment = $order->getPayment();
        if ($payment === null) {
            $this->logger->error('Order has no payment instance', [
                'order_id' => $order->getIncrementId(),
            ]);
            return;
        }

        $order->addStatusHistoryComment($message)->setIsCustomerNotified(false);

        $payment->setTransactionId($transactionId)
            ->setShouldCloseParentTransaction(false)
            ->setIsTransactionClosed(0)
            ->registerCaptureNotification($amount, true);

        $invoice = $payment->getCreatedInvoice();

        // If registerCaptureNotification didn't automatically create the invoice, force it
        if (!$invoice && $order->canInvoice()) {
            try {
                $invoice = $this->invoiceService->prepareInvoice($order);
                $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                $invoice->register();
                $invoice->save();

                $transactionSave = $this->transactionFactory->create()
                    ->addObject($invoice)
                    ->addObject($invoice->getOrder());
                $transactionSave->save();
            } catch (\Exception $e) {
                $this->logger->error('Failed to automatically generate invoice', [
                    'order_id' => $order->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->orderRepository->save($order);

        if ($invoice && !$invoice->getEmailSent()) {
            try {
                $this->invoiceSender->send($invoice);
                $order->addStatusHistoryComment(
                    __('You notified customer about invoice #%1.', $invoice->getIncrementId())
                )->setIsCustomerNotified(true);
                $this->orderRepository->save($order);
            } catch (\Exception $e) {
                $this->logger->warning('Failed to send invoice notification', [
                    'order_id' => $order->getIncrementId(),
                    'invoice_id' => $invoice->getIncrementId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Check if order has already been successfully processed.
     */
    private function isAlreadyProcessed(Order $order): bool
    {
        $state = $order->getState();
        return in_array($state, [
            Order::STATE_PROCESSING,
            Order::STATE_COMPLETE,
        ], true);
    }

    /**
     * Get the configured order status or default to 'pending'.
     */
    private function getConfiguredOrderStatus(): string
    {
        $status = (string) $this->scopeConfig->getValue(
            self::CONFIG_PATH_ORDER_STATUS,
            ScopeInterface::SCOPE_STORE
        );

        return !empty($status) ? $status : 'pending';
    }
}
