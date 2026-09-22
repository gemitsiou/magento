<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Model\Config\Source\Order\Status;

use Magento\Sales\Model\Config\Source\Order\Status;
use Magento\Sales\Model\Order;

/**
 * Order status source model for Viva Payments configuration.
 *
 * Provides available order statuses for the admin payment configuration.
 */
class Pendingpayment extends Status
{
    /**
     * Allowed order states for status selection.
     *
     * @var string[]
     */
    protected $_stateStatuses = [
        Order::STATE_PENDING_PAYMENT,
        Order::STATE_PROCESSING,
        Order::STATE_COMPLETE,
        Order::STATE_CLOSED,
        Order::STATE_CANCELED,
        Order::STATE_HOLDED,
        Order::STATE_PAYMENT_REVIEW,
    ];
}
