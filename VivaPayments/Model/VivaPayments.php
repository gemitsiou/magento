<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Model;

use Magento\Framework\Model\AbstractModel;

/**
 * Viva Payments data model.
 *
 * Tracks payment order codes, references, and states
 * for Viva Wallet transactions.
 */
class VivaPayments extends AbstractModel
{
    /**
     * Initialize resource model.
     */
    protected function _construct(): void
    {
        $this->_init(\Ced\VivaPayments\Model\ResourceModel\VivaPayments::class);
    }
}