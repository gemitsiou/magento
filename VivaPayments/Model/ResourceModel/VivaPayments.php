<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Viva Payments resource model.
 */
class VivaPayments extends AbstractDb
{
    /**
     * Initialize table and primary key.
     */
    protected function _construct(): void
    {
        $this->_init('vivapayments_data', 'id');
    }
}