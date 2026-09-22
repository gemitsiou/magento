<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Controller\Checkout;

use Ced\VivaPayments\Model\PaymentMethod;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * Initiates checkout by creating a Viva payment order and redirecting to the gateway.
 */
class Start implements HttpGetActionInterface
{
    private PaymentMethod $paymentMethod;
    private CheckoutSession $checkoutSession;
    private RawFactory $rawResultFactory;
    private RedirectFactory $redirectFactory;
    private LoggerInterface $logger;

    public function __construct(
        PaymentMethod $paymentMethod,
        CheckoutSession $checkoutSession,
        RawFactory $rawResultFactory,
        RedirectFactory $redirectFactory,
        LoggerInterface $logger
    ) {
        $this->paymentMethod = $paymentMethod;
        $this->checkoutSession = $checkoutSession;
        $this->rawResultFactory = $rawResultFactory;
        $this->redirectFactory = $redirectFactory;
        $this->logger = $logger;
    }

    /**
     * Generate redirect form to Viva gateway.
     *
     * @return Raw|Redirect
     */
    public function execute(): Raw|Redirect
    {
        try {
            $order = $this->checkoutSession->getLastRealOrder();

            if (!$order || !$order->getId()) {
                $this->logger->error('VivaPayments Start: No order found in session');
                return $this->createErrorRedirect();
            }

            $html = $this->paymentMethod->getRedirectFormHtml($order);

            /** @var Raw $result */
            $result = $this->rawResultFactory->create();
            $result->setHeader('Content-Type', 'text/html');
            $result->setContents($html);

            return $result;
        } catch (LocalizedException $e) {
            $this->logger->error('VivaPayments Start error', [
                'message' => $e->getMessage(),
            ]);
            return $this->createErrorRedirect();
        } catch (\Exception $e) {
            $this->logger->critical('VivaPayments Start unexpected error', [
                'exception' => $e->getMessage(),
            ]);
            return $this->createErrorRedirect();
        }
    }

    /**
     * Create a redirect back to checkout/cart on error.
     */
    private function createErrorRedirect(): Redirect
    {
        /** @var Redirect $redirect */
        $redirect = $this->redirectFactory->create();
        $redirect->setPath('checkout/cart');
        return $redirect;
    }
}