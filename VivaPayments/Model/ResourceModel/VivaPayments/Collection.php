<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Model\ResourceModel\VivaPayments;

use Ced\VivaPayments\Model\VivaPayments as VivaPaymentsModel;
use Ced\VivaPayments\Model\ResourceModel\VivaPayments as VivaPaymentsResource;
use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;

/**
 * Viva Payments data collection.
 */
class Collection extends AbstractCollection
{
    /**
     * Initialize model and resource model.
     */
    protected function _construct(): void
    {
        $this->_init(VivaPaymentsModel::class, VivaPaymentsResource::class);
    }
}
