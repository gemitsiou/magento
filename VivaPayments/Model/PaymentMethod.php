<?php
declare(strict_types=1);

namespace Ced\VivaPayments\Model;

use Ced\VivaPayments\Model\ResourceModel\VivaPayments as VivaPaymentsResource;
use Ced\VivaPayments\Service\VivaApiClient;
use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Viva Wallet Smart Checkout payment method.
 *
 * Handles redirect-based payment integration with Viva Wallet.
 * The customer is redirected to Viva's hosted checkout page after order placement.
 *
 * Note: AbstractMethod is deprecated in Magento 2.4.x but still functional.
 * A full migration to the Payment Gateway framework (facade/command pattern)
 * is recommended as a follow-up for this redirect-based integration.
 */
class PaymentMethod extends AbstractMethod
{
    private const CURRENCY_MAP = [
        'HRK' => 191,
        'CZK' => 203,
        'DKK' => 208,
        'HUF' => 348,
        'SEK' => 752,
        'GBP' => 826,
        'RON' => 946,
        'BGN' => 975,
        'EUR' => 978,
        'PLN' => 985,
    ];

    private const DEFAULT_CURRENCY_CODE = 978; // EUR

    private const SUPPORTED_LANGUAGES = [
        'el-GR', 'bg-BG', 'cs-CZ', 'da-DK', 'de-DE', 'es-ES',
        'fi-FI', 'fr-FR', 'hr-HR', 'hu-HU', 'it-IT', 'nl-NL',
        'pl-PL', 'pt-PT', 'ro-RO', 'en-GB',
    ];

    /**
     * Payment method code.
     *
     * @var string
     */
    protected $_code = 'paymentmethod';

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var bool
     */
    protected $_canCapture = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial = true;

    private StoreManagerInterface $storeManager;
    private ResolverInterface $localeResolver;
    private UrlInterface $urlBuilder;
    private VivaApiClient $vivaApiClient;
    private VivaPaymentsFactory $vivaPaymentsFactory;
    private VivaPaymentsResource $vivaResource;

    /**
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ExtensionAttributesFactory $extensionFactory,
        AttributeValueFactory $customAttributeFactory,
        PaymentData $paymentData,
        ScopeConfigInterface $scopeConfig,
        Logger $logger,
        StoreManagerInterface $storeManager,
        ResolverInterface $localeResolver,
        UrlInterface $urlBuilder,
        VivaApiClient $vivaApiClient,
        VivaPaymentsFactory $vivaPaymentsFactory,
        VivaPaymentsResource $vivaResource,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );
        $this->storeManager = $storeManager;
        $this->localeResolver = $localeResolver;
        $this->urlBuilder = $urlBuilder;
        $this->vivaApiClient = $vivaApiClient;
        $this->vivaPaymentsFactory = $vivaPaymentsFactory;
        $this->vivaResource = $vivaResource;
    }

    /**
     * Get supported currency codes from config.
     *
     * @return string[]
     */
    public function getSupportedCurrencyCodes(): array
    {
        $allowed = $this->getConfigData('allowed_currency');
        return $allowed ? explode(',', (string) $allowed) : [];
    }

    /**
     * Check if the payment method can be used for the given currency.
     *
     * @param string $currencyCode
     */
    public function canUseForCurrency($currencyCode): bool
    {
        return in_array((string) $currencyCode, $this->getSupportedCurrencyCodes(), true);
    }

    /**
     * Initialize payment - set order to pending_payment state.
     *
     * @param string $paymentAction
     * @param object $stateObject
     */
    public function initialize($paymentAction, $stateObject): self
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);

        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * Generate the HTML form that auto-submits to redirect customer to Viva gateway.
     *
     * @throws LocalizedException
     */
    public function getRedirectFormHtml(Order $order): string
    {
        $billingAddress = $order->getBillingAddress();
        if ($billingAddress === null) {
            throw new LocalizedException(__('Billing address is required.'));
        }

        $currencyCode = (string) $order->getBaseCurrencyCode();
        $currencyNumeric = self::CURRENCY_MAP[$currencyCode] ?? self::DEFAULT_CURRENCY_CODE;
        $locale = $this->resolveLocale();
        $amountCents = (int) round((float) $order->getBaseGrandTotal() * 100);

        $storeName = $this->_scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE
        );

        $orderData = [
            'amount_cents' => $amountCents,
            'currency_code' => $currencyNumeric,
            'merchant_trns' => $order->getIncrementId(),
            'email' => (string) $billingAddress->getEmail(),
            'phone' => (string) $billingAddress->getTelephone(),
            'full_name' => trim($billingAddress->getFirstname() . ' ' . $billingAddress->getLastname()),
            'locale' => $locale,
            'store_name' => $storeName,
        ];

        $vivaOrderCode = $this->vivaApiClient->createPaymentOrder($orderData, (int) $order->getStoreId());

        $this->savePaymentRecord($order, $vivaOrderCode, $amountCents, $currencyNumeric);

        $gatewayUrl = htmlspecialchars(
            (string) $this->getConfigData('cgi_url'),
            ENT_QUOTES,
            'UTF-8'
        );
        $escapedOrderCode = htmlspecialchars((string) $vivaOrderCode, ENT_QUOTES, 'UTF-8');

        // Optionally append brand color to Smart Checkout redirect
        $colorInput = '';
        $checkoutColor = trim((string) $this->getConfigData('checkout_color'));
        if ($checkoutColor !== '') {
            $escapedColor = htmlspecialchars($checkoutColor, ENT_QUOTES, 'UTF-8');
            $colorInput = '<input type="hidden" name="color" value="' . $escapedColor . '"/>';
        }

        return sprintf(
            '<!DOCTYPE html><html><body>'
            . '<form action="%s" method="GET" id="vivapayment_form">'
            . '<input type="hidden" name="Ref" value="%s"/>'
            . '%s'
            . '</form>'
            . '<script type="text/javascript">document.getElementById("vivapayment_form").submit();</script>'
            . '</body></html>',
            $gatewayUrl,
            $escapedOrderCode,
            $colorInput
        );
    }

    /**
     * Get the success URL.
     */
    public function getSuccessUrl(?int $storeId = null): string
    {
        return $this->buildUrl('checkout/onepage/success', $storeId);
    }

    /**
     * Get the cancel/failure URL.
     */
    public function getCancelUrl(?int $storeId = null): string
    {
        return $this->buildUrl('checkout/onepage/failure', $storeId);
    }

    /**
     * Validate payment info.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function validate(): self
    {
        $data = $this->getInfoInstance();
        if ($data instanceof \Magento\Sales\Model\Order\Payment) {
            $billingCountry = (string) $data->getOrder()->getBillingAddress()->getCountryId();
            $currencyCode = (string) $data->getOrder()->getBaseCurrencyCode();
        } else {
            $billingCountry = (string) $data->getQuote()->getBillingAddress()->getCountryId();
            $currencyCode = (string) $data->getQuote()->getBaseCurrencyCode();
        }

        if (!$this->canUseForCountry($billingCountry)) {
            throw new LocalizedException(
                __('Selected payment type is not allowed for billing country.')
            );
        }

        $supportedCurrencies = $this->getSupportedCurrencyCodes();
        if (!empty($currencyCode) && !empty($supportedCurrencies)
            && !in_array($currencyCode, $supportedCurrencies, true)
        ) {
            throw new LocalizedException(
                __('Selected currency is not supported by this payment method.')
            );
        }

        return $this;
    }

    /**
     * Refund specified amount for payment.
     *
     * Issues an online refund API call to Viva Wallet and records the refund transaction.
     *
     * @param InfoInterface $payment
     * @param float|string $amount
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount): self
    {
        if (!$this->canRefund()) {
            throw new LocalizedException(__('The refund action is not available.'));
        }

        if (!$payment instanceof OrderPayment) {
            throw new LocalizedException(__('Invalid payment instance for refund.'));
        }

        $order = $payment->getOrder();
        if ($order === null) {
            throw new LocalizedException(__('Order not found for refund.'));
        }

        // Locate original transaction ID
        $creditmemo = $payment->getCreditmemo();
        $transactionId = (string) $payment->getParentTransactionId();

        if (empty($transactionId) && $creditmemo && $creditmemo->getInvoice()) {
            $transactionId = (string) $creditmemo->getInvoice()->getTransactionId();
        }

        if (empty($transactionId)) {
            $transactionId = (string) $payment->getLastTransId();
        }

        if (empty($transactionId)) {
            throw new LocalizedException(
                __('Cannot refund: no valid transaction ID found on this payment.')
            );
        }

        $currencyCode = (string) $order->getBaseCurrencyCode();
        $currencyNumeric = self::CURRENCY_MAP[$currencyCode] ?? self::DEFAULT_CURRENCY_CODE;
        $amountCents = (int) round((float) $amount * 100);

        $result = $this->vivaApiClient->refundTransaction(
            $transactionId,
            $amountCents,
            $currencyNumeric,
            (string) $order->getIncrementId(),
            (int) $order->getStoreId()
        );

        $refundTransId = !empty($result['transaction_id'])
            ? $result['transaction_id']
            : $transactionId . '-refund';

        $payment->setTransactionId($refundTransId);
        $payment->setIsTransactionClosed(1);

        $shouldCloseParent = false;
        if ($creditmemo && $creditmemo->getInvoice()) {
            $shouldCloseParent = !$creditmemo->getInvoice()->canRefund();
        }
        $payment->setShouldCloseParentTransaction($shouldCloseParent);

        $orderComment = sprintf(
            "Viva Wallet Smart Checkout Refund Processed\nOriginal TxID: %s\nRefund TxID: %s",
            $transactionId,
            $refundTransId
        );
        $order->addCommentToStatusHistory($orderComment, false);

        return $this;
    }

    /**
     * Save a Viva payment tracking record.
     */
    private function savePaymentRecord(
        Order $order,
        string $orderCode,
        int $amountCents,
        int $currencyNumeric
    ): void {
        $billingAddress = $order->getBillingAddress();
        $ref = 'REF' . substr(md5(uniqid((string) random_int(0, PHP_INT_MAX), true)), 0, 9);

        $vivaRecord = $this->vivaPaymentsFactory->create();
        $vivaRecord->addData([
            'ref' => $ref,
            'ordercode' => $orderCode,
            'email_address' => $billingAddress ? (string) $billingAddress->getEmail() : '',
            'order_id' => $order->getIncrementId(),
            'total_cost' => $amountCents,
            'currency' => (string) $currencyNumeric,
            'order_state' => 'Pending_payment',
            'timestamp' => date('Y-m-d H:i:s'),
        ]);
        $this->vivaResource->save($vivaRecord);
    }

    /**
     * Resolve the checkout language based on store locale.
     */
    private function resolveLocale(): string
    {
        $locale = (string) $this->localeResolver->getLocale();
        // Convert underscore format (en_GB) to dash format (en-GB) if needed
        $locale = str_replace('_', '-', $locale);

        if (!in_array($locale, self::SUPPORTED_LANGUAGES, true)) {
            return 'en-GB';
        }

        return $locale;
    }

    /**
     * Build a store-aware URL.
     */
    private function buildUrl(string $path, ?int $storeId = null): string
    {
        $store = $this->storeManager->getStore($storeId);

        return $this->urlBuilder->getUrl(
            $path,
            [
                '_store' => $store,
                '_secure' => $store->isCurrentlySecure(),
            ]
        );
    }
}
