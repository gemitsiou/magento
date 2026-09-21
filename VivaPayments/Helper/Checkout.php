<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Helper;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;

/**
 * Checkout helper for order cancellation and quote restoration.
 */
class Checkout
{
    private CheckoutSession $session;
    private OrderRepositoryInterface $orderRepository;

    public function __construct(
        CheckoutSession $session,
        OrderRepositoryInterface $orderRepository
    ) {
        $this->session = $session;
        $this->orderRepository = $orderRepository;
    }

    /**
     * Cancel last placed order with specified comment message.
     *
     * @param string $comment Comment appended to order history
     * @return bool True if order cancelled, false otherwise
     */
    public function cancelCurrentOrder(string $comment): bool
    {
        $order = $this->session->getLastRealOrder();
        if ($order->getId() && $order->getState() !== Order::STATE_CANCELED) {
            $order->registerCancellation($comment);
            $this->orderRepository->save($order);
            return true;
        }
        return false;
    }

    /**
     * Restores the quote so the customer can retry checkout.
     */
    public function restoreQuote(): bool
    {
        return $this->session->restoreQuote();
    }
}
