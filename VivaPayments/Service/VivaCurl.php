<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Service;

use Magento\Framework\HTTP\Client\Curl;

/**
 * Magento HTTP client with DELETE support for Viva refunds.
 */
class VivaCurl extends Curl
{
    public function delete(string $uri): void
    {
        $this->makeRequest('DELETE', $uri);
    }
}
