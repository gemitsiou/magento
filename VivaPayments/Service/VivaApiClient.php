<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Service;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Viva Wallet API Client
 *
 * Handles all communication with the Viva Wallet payment gateway.
 * Uses Magento's HTTP client abstraction with SSL verification enabled.
 */
class VivaApiClient
{
    private const CONFIG_PATH_MERCHANT_ID = 'payment/paymentmethod/merchantid';
    private const CONFIG_PATH_API_KEY = 'payment/paymentmethod/merchantpass';
    private const CONFIG_PATH_ORDER_URL = 'payment/paymentmethod/order_url';
    private const CONFIG_PATH_TRANSACTION_URL = 'payment/paymentmethod/transaction_url';
    private const CONFIG_PATH_SOURCE_CODE = 'payment/paymentmethod/merchantsource';
    private const CONFIG_PATH_INSTALLMENTS = 'payment/paymentmethod/Installments';

    private ScopeConfigInterface $scopeConfig;
    private VivaCurlFactory $curlFactory;
    private LoggerInterface $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        VivaCurlFactory $curlFactory,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->curlFactory = $curlFactory;
        $this->logger = $logger;
    }

    /**
     * Create a payment order on Viva Wallet and return the order code.
     *
     * @param array $orderData Associative array with keys:
     *   - amount_cents (int)
     *   - currency_code (int)
     *   - merchant_trns (string) - order increment ID
     *   - email (string)
     *   - phone (string)
     *   - full_name (string)
     *   - locale (string)
     *   - store_name (string|null)
     * @return string The Viva order code
     * @throws LocalizedException
     */
    public function createPaymentOrder(array $orderData, ?int $storeId = null): string
    {
        $orderUrl = $this->getConfigValue(self::CONFIG_PATH_ORDER_URL, $storeId);
        $sourceCode = $this->getConfigValue(self::CONFIG_PATH_SOURCE_CODE, $storeId);

        $maxInstallments = $this->calculateMaxInstallments(
            (int) $orderData['amount_cents'],
            $storeId
        );

        $postFields = [
            'Amount' => (string) $orderData['amount_cents'],
            'RequestLang' => $orderData['locale'],
            'Email' => $orderData['email'],
            'MaxInstallments' => (string) $maxInstallments,
            'MerchantTrns' => $orderData['merchant_trns'],
            'SourceCode' => $sourceCode,
            'CurrencyCode' => (string) $orderData['currency_code'],
            'DisableCash' => 'true',
            'FullName' => $orderData['full_name'],
        ];

        if (!empty($orderData['store_name'])) {
            $postFields['CustomerTrns'] = $orderData['store_name'];
        }

        $postString = http_build_query($postFields);

        try {
            $curl = $this->createAuthenticatedClient($storeId);
            $curl->post($orderUrl, $postString);

            $response = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Viva API order creation failed', [
                    'status_code' => $statusCode,
                    'order_increment_id' => $orderData['merchant_trns'],
                ]);
                throw new LocalizedException(
                    __('Payment gateway communication error. Please try again later.')
                );
            }

            $result = $this->parseResponse($response);

            if (($result['ErrorCode'] ?? -1) !== 0) {
                $this->logger->error('Viva API returned error on order creation', [
                    'error_code' => $result['ErrorCode'] ?? 'unknown',
                    'order_increment_id' => $orderData['merchant_trns'],
                ]);
                throw new LocalizedException(
                    __('Unable to initialize payment. Please try again or use a different payment method.')
                );
            }

            return (string) ($result['OrderCode'] ?? '');
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Viva API unexpected error during order creation', [
                'exception' => $e->getMessage(),
                'order_increment_id' => $orderData['merchant_trns'] ?? 'unknown',
            ]);
            throw new LocalizedException(
                __('Payment service is temporarily unavailable. Please try again later.')
            );
        }
    }

    /**
     * Verify a transaction with Viva Wallet API.
     *
     * @param string $orderCode The Viva order code
     * @param string|null $transactionId Optional specific transaction ID to look for
     * @return array{verified: bool, transaction_id: string, amount: float, status_id: string, message: string}
     */
    public function verifyTransaction(string $orderCode, ?string $transactionId = null, ?int $storeId = null): array
    {
        $transactionUrl = $this->getConfigValue(self::CONFIG_PATH_TRANSACTION_URL, $storeId);
        $requestUrl = $transactionUrl . '?ordercode=' . urlencode($orderCode);

        $defaultResult = [
            'verified' => false,
            'transaction_id' => '',
            'amount' => 0.0,
            'status_id' => '',
            'message' => '',
        ];

        try {
            $curl = $this->createAuthenticatedClient($storeId);
            $curl->get($requestUrl);

            $response = $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Viva API transaction verification failed', [
                    'status_code' => $statusCode,
                    'order_code' => $orderCode,
                ]);
                $defaultResult['message'] = 'Payment verification failed. Please contact support.';
                return $defaultResult;
            }

            $result = $this->parseResponse($response);

            if (($result['ErrorCode'] ?? -1) !== 0) {
                $this->logger->error('Viva API returned error on transaction verification', [
                    'error_code' => $result['ErrorCode'] ?? 'unknown',
                    'error_text' => $result['ErrorText'] ?? 'unknown',
                    'order_code' => $orderCode,
                ]);
                $defaultResult['message'] = 'Payment verification returned an error. Please contact support.';
                return $defaultResult;
            }

            $transactions = $result['Transactions'] ?? [];
            if (empty($transactions)) {
                $this->logger->warning('No transactions found for order code', [
                    'order_code' => $orderCode,
                ]);
                $defaultResult['message'] = 'No transactions found for this payment.';
                return $defaultResult;
            }

            $transaction = $this->findTransaction($transactions, $transactionId);

            $statusId = strtoupper((string) ($transaction['StatusId'] ?? ''));
            $isSuccess = $statusId === 'F';

            return [
                'verified' => $isSuccess,
                'transaction_id' => (string) ($transaction['TransactionId'] ?? ''),
                'amount' => (float) ($transaction['Amount'] ?? 0),
                'status_id' => $statusId,
                'message' => $isSuccess
                    ? 'Transaction completed successfully.'
                    : 'Transaction was not completed successfully.',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Viva API unexpected error during transaction verification', [
                'exception' => $e->getMessage(),
                'order_code' => $orderCode,
            ]);
            $defaultResult['message'] = 'Payment verification encountered an error. Please contact support.';
            return $defaultResult;
        }
    }

    /**
     * Refund or cancel a transaction with Viva Wallet API.
     *
     * Issues a DELETE request to /api/transactions/{transactionId} with Basic Auth.
     *
     * @param string $transactionId Original transaction ID to refund
     * @param int $amountCents Amount to refund in smallest currency denomination (cents)
     * @param int|null $currencyNumeric Numeric currency code (e.g. 978 for EUR)
     * @param string|null $orderIncrementId Magento order increment ID (for merchantTrns)
     * @param int|null $storeId Store scope ID
     * @return array{success: bool, transaction_id: string, message: string}
     * @throws LocalizedException
     */
    public function refundTransaction(
        string $transactionId,
        int $amountCents,
        ?int $currencyNumeric = null,
        ?string $orderIncrementId = null,
        ?int $storeId = null
    ): array {
        if (empty($transactionId)) {
            throw new LocalizedException(__('Transaction ID is required for refund.'));
        }

        if ($amountCents <= 0) {
            throw new LocalizedException(__('Refund amount must be greater than zero.'));
        }

        $transactionUrl = $this->getConfigValue(self::CONFIG_PATH_TRANSACTION_URL, $storeId);
        $sourceCode = $this->getConfigValue(self::CONFIG_PATH_SOURCE_CODE, $storeId);

        $queryParams = [
            'amount' => $amountCents,
        ];

        if (!empty($sourceCode)) {
            $queryParams['sourceCode'] = $sourceCode;
        }

        if ($currencyNumeric !== null && $currencyNumeric > 0) {
            $queryParams['currencyCode'] = (string) $currencyNumeric;
        }

        if (!empty($orderIncrementId)) {
            $queryParams['merchantTrns'] = $orderIncrementId;
        }

        $requestUrl = rtrim($transactionUrl, '/') . '/' . urlencode($transactionId) . '?' . http_build_query($queryParams);

        try {
            $curl = $this->createAuthenticatedClient($storeId);
            $curl->addHeader('Accept', 'application/json');
            $curl->delete($requestUrl);

            $response = (string) $curl->getBody();
            $statusCode = $curl->getStatus();

            if ($statusCode < 200 || $statusCode >= 300) {
                $this->logger->error('Viva API refund HTTP request failed', [
                    'status_code' => $statusCode,
                    'transaction_id' => $transactionId,
                ]);

                $errorMessage = __('Payment gateway refund request failed (HTTP %1).', $statusCode);
                try {
                    $errorData = $this->parseResponse($response);
                    if (!empty($errorData['Message'])) {
                        $errorMessage = __('Viva Wallet refund failed: %1', (string) $errorData['Message']);
                    } elseif (!empty($errorData['ErrorText'])) {
                        $errorMessage = __('Viva Wallet refund failed: %1', (string) $errorData['ErrorText']);
                    } elseif (is_array($errorData) && !empty($errorData)) {
                        $firstVal = reset($errorData);
                        if (is_string($firstVal) && !empty($firstVal)) {
                            $errorMessage = __('Viva Wallet refund failed: %1', $firstVal);
                        }
                    }
                } catch (\Exception) {
                    // Fallback to generic status code message
                }

                throw new LocalizedException($errorMessage);
            }

            $result = $this->parseResponse($response);

            $errorCode = $result['ErrorCode'] ?? 0;
            if ($errorCode !== 0) {
                $errorText = (string) ($result['ErrorText'] ?? 'Unknown gateway error');
                $this->logger->error('Viva API returned error on refund', [
                    'error_code' => $errorCode,
                    'error_text' => $errorText,
                    'transaction_id' => $transactionId,
                ]);
                throw new LocalizedException(
                    __('Viva Wallet refund failed: %1', $errorText)
                );
            }

            $statusId = strtoupper((string) ($result['StatusId'] ?? ''));
            $refundTransactionId = (string) ($result['TransactionId'] ?? '');

            $isApproved = $statusId === 'F';

            if (!$isApproved) {
                $this->logger->warning('Viva API refund transaction was not approved', [
                    'status_id' => $statusId,
                    'transaction_id' => $transactionId,
                ]);
                throw new LocalizedException(
                    __('Viva Wallet did not approve the refund (Status: %1).', $statusId ?: 'Unknown')
                );
            }

            $this->logger->info('Viva API refund succeeded', [
                'original_transaction_id' => $transactionId,
                'refund_transaction_id' => $refundTransactionId,
                'amount_cents' => $amountCents,
                'status_id' => $statusId,
            ]);

            return [
                'success' => true,
                'transaction_id' => $refundTransactionId ?: $transactionId . '-refund',
                'message' => 'Refund completed successfully.',
            ];
        } catch (LocalizedException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('Viva API unexpected error during refund', [
                'exception' => $e->getMessage(),
                'transaction_id' => $transactionId,
            ]);
            throw new LocalizedException(
                __('An unexpected error occurred while processing the refund with Viva Wallet: %1', $e->getMessage())
            );
        }
    }

    /**
     * Create an authenticated Curl client with Viva credentials.
     */
    private function createAuthenticatedClient(?int $storeId = null): VivaCurl
    {
        $merchantId = $this->getConfigValue(self::CONFIG_PATH_MERCHANT_ID, $storeId);
        $apiKey = $this->getConfigValue(self::CONFIG_PATH_API_KEY, $storeId);

        /** @var VivaCurl $curl */
        $curl = $this->curlFactory->create();
        $curl->setCredentials($merchantId, $apiKey);
        $curl->setOption(CURLOPT_RETURNTRANSFER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYPEER, true);
        $curl->setOption(CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, 30);
        $curl->setOption(CURLOPT_TIMEOUT, 60);

        return $curl;
    }

    /**
     * Parse JSON response from Viva API.
     *
     * @throws LocalizedException
     */
    private function parseResponse(string $response): array
    {
        $decoded = json_decode($response, true, 512, JSON_BIGINT_AS_STRING);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error('Failed to parse Viva API response', [
                'json_error' => json_last_error_msg(),
            ]);
            throw new LocalizedException(
                __('Invalid response from payment gateway. Please try again later.')
            );
        }

        return $decoded;
    }

    /**
     * Find a specific transaction or the most recent one.
     */
    private function findTransaction(array $transactions, ?string $transactionId): array
    {
        if (!empty($transactionId)) {
            foreach ($transactions as $t) {
                if (($t['TransactionId'] ?? '') === $transactionId) {
                    return $t;
                }
            }
        }

        // Sort by InsDate descending (most recent first)
        usort($transactions, static function (array $a, array $b): int {
            return strtotime((string) ($b['InsDate'] ?? '')) - strtotime((string) ($a['InsDate'] ?? ''));
        });

        return $transactions[0];
    }

    /**
     * Calculate max installments based on amount and config.
     */
    private function calculateMaxInstallments(int $amountCents, ?int $storeId = null): int
    {
        $maxPeriod = 1;
        $installConfig = $this->getConfigValue(self::CONFIG_PATH_INSTALLMENTS, $storeId);

        if (empty($installConfig)) {
            return $maxPeriod;
        }

        // Amount in config is in major currency units, amountCents is in cents
        $amountMajor = $amountCents / 100;
        $entries = explode(',', $installConfig);
        $applicableTerms = [];

        foreach ($entries as $entry) {
            $parts = explode(':', trim($entry));
            if (count($parts) !== 2) {
                continue;
            }
            $threshold = (float) trim($parts[0]);
            $term = (int) trim($parts[1]);
            if ($amountMajor >= $threshold) {
                $applicableTerms[] = $term;
            }
        }

        if (!empty($applicableTerms)) {
            $maxPeriod = max($applicableTerms);
        }

        return $maxPeriod;
    }

    /**
     * Get a config value from the store scope.
     */
    private function getConfigValue(string $path, ?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            $path,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
