<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Controller\Viva;

use Ced\VivaPayments\Service\OrderProcessor;
use Ced\VivaPayments\Service\VivaApiClient;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Psr\Log\LoggerInterface;

/**
 * Handles the customer return from Viva Wallet after payment.
 *
 * This is a customer-browser redirect (not a server-to-server webhook).
 * Security is ensured by verifying the transaction with Viva's API using
 * the order code — if Viva confirms the transaction, it's authentic.
 *
 * CSRF note: Since this is a GET redirect from an external site, traditional
 * form keys are not available. The Viva API transaction verification serves
 * as the authentication mechanism (order codes are unguessable and tied to
 * authenticated merchant API credentials).
 */
class Callback implements HttpGetActionInterface
{
    private VivaApiClient $vivaApiClient;
    private OrderProcessor $orderProcessor;
    private CheckoutSession $checkoutSession;
    private RedirectFactory $redirectFactory;
    private MessageManager $messageManager;
    private LoggerInterface $logger;
    private RequestInterface $request;

    public function __construct(
        VivaApiClient $vivaApiClient,
        OrderProcessor $orderProcessor,
        CheckoutSession $checkoutSession,
        RedirectFactory $redirectFactory,
        MessageManager $messageManager,
        LoggerInterface $logger,
        RequestInterface $request
    ) {
        $this->vivaApiClient = $vivaApiClient;
        $this->orderProcessor = $orderProcessor;
        $this->checkoutSession = $checkoutSession;
        $this->redirectFactory = $redirectFactory;
        $this->messageManager = $messageManager;
        $this->logger = $logger;
        $this->request = $request;
    }

    /**
     * Process payment callback from Viva Wallet.
     */
    public function execute(): Redirect
    {
        try {
            return $this->processCallback();
        } catch (\Exception $e) {
            $this->logger->error('VivaPayments Callback unexpected error', [
                'exception' => $e->getMessage(),
            ]);
            $this->messageManager->addErrorMessage(
                __('An error occurred processing your payment. Please contact support.')
            );
            return $this->redirectToCart();
        }
    }

    /**
     * Core callback processing logic.
     */
    private function processCallback(): Redirect
    {
        $orderCode = (string) $this->request->getParam('s', '');
        $transactionId = (string) $this->request->getParam('t', '');

        if (empty($orderCode)) {
            $this->logger->error('VivaPayments Callback: Missing order code parameter');
            $this->messageManager->addErrorMessage(
                __('Invalid payment callback. Missing payment reference.')
            );
            return $this->redirectToCart();
        }

        // Load the Magento order via the Viva order code stored in vivapayments_data.
        // This is session-independent: the order code is the authoritative link.
        try {
            $order = $this->orderProcessor->loadOrderByVivaCode($orderCode);
        } catch (NoSuchEntityException $e) {
            $this->logger->error('VivaPayments Callback: Cannot load order for code', [
                'order_code' => $orderCode,
            ]);
            $this->messageManager->addErrorMessage(
                __('Unable to retrieve your order. Please contact support.')
            );
            return $this->redirectToCart();
        }

        // Verify the transaction with Viva Wallet API
        $verification = $this->vivaApiClient->verifyTransaction(
            $orderCode,
            $transactionId,
            (int) $order->getStoreId()
        );

        if ($verification['verified']) {
            // Successful payment
            $this->orderProcessor->updateVivaPaymentState($orderCode, 'paid');
            $this->orderProcessor->processSuccessfulPayment(
                $order,
                $verification['transaction_id'],
                $verification['amount'],
                $verification['message']
            );

            return $this->redirectToSuccess();
        }

        // Failed payment
        $this->orderProcessor->updateVivaPaymentState($orderCode, 'failed');
        $this->orderProcessor->processFailedPayment($order, $verification['message']);
        $this->checkoutSession->restoreQuote();

        $this->messageManager->addErrorMessage(
            __('Your transaction failed or has been cancelled.')
        );

        return $this->redirectToCart();
    }

    private function redirectToSuccess(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->redirectFactory->create();
        $redirect->setPath('checkout/onepage/success');
        return $redirect;
    }

    private function redirectToCart(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->redirectFactory->create();
        $redirect->setPath('checkout/cart');
        return $redirect;
    }
}
