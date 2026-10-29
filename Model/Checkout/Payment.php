<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Model\Checkout;

use Magento\Framework\Api\AttributeValueFactory;
use Magento\Framework\Api\ExtensionAttributesFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentData;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Payment\Model\Method\Logger;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Zero\Checkout\Controller\Checkout\Placeorder;
use Zero\Checkout\Model\RedirectPayment\ReturnUrl;

/**
 * PayPal Checkout (zero_paypal_checkout, Orders API v2): a PayPal order
 * (intent CAPTURE) for the total; the order waits in pending payment and the
 * money is captured on return (OrderUpdater). Nothing is taken without that,
 * so no webhook is needed. Credit memos refund the capture.
 */
class Payment extends AbstractMethod
{
    public const CODE = 'zero_paypal_checkout';
    public const PAYPAL_ORDER_ID = 'paypal_order_id';
    public const CAPTURE_ID = 'paypal_capture_id';

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isInitializeNeeded = true;

    /**
     * @var bool
     */
    protected $_canUseInternal = false;

    /**
     * @var bool
     */
    protected $_canRefund = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

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
        private readonly Api $api,
        private readonly UrlInterface $urlBuilder,
        private readonly ReturnUrl $returnUrl,
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
    }

    /**
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        return parent::isAvailable($quote) && $this->api->isConfigured($quote ? (int) $quote->getStoreId() : null);
    }

    /**
     * @param string $paymentAction
     * @param object $stateObject
     * @return $this
     * @throws LocalizedException
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        /** @var Order $order */
        $order = $payment->getOrder();
        $reference = (string) $order->getIncrementId();
        $storeId = (int) $order->getStoreId();
        $urlParams = ['_secure' => true, '_query' => ['order' => $reference]];
        $answer = $this->api->createOrder([
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $reference,
                'custom_id' => $reference,
                'description' => (string) __('Order #%1', $reference),
                'amount' => self::amount($order, (float) $order->getGrandTotal()),
            ]],
            'payment_source' => ['paypal' => ['experience_context' => [
                'return_url' => $this->returnUrl->headless($order, self::CODE, ['outcome' => 'return'])
                    ?? $this->urlBuilder->getUrl('zero_paypal/checkout/return', $urlParams),
                'cancel_url' => $this->returnUrl->headless($order, self::CODE, ['outcome' => 'cancel'])
                    ?? $this->urlBuilder->getUrl('zero_paypal/checkout/cancel', $urlParams),
                'user_action' => 'PAY_NOW',
                'shipping_preference' => 'NO_SHIPPING',
                'brand_name' => mb_substr((string) $this->_scopeConfig->getValue(
                    'general/store_information/name',
                    ScopeInterface::SCOPE_STORE,
                    $storeId
                ) ?: (string) $order->getStore()->getFrontendName(), 0, 127),
            ]]],
        ], $storeId);
        $approveUrl = '';
        foreach ((array) ($answer['links'] ?? []) as $link) {
            if (($link['rel'] ?? '') === 'payer-action') {
                $approveUrl = (string) $link['href'];
            }
        }
        if (empty($answer['id']) || $approveUrl === '') {
            throw new LocalizedException(__('PayPal didn\'t create the payment. Please try again or choose another payment method.'));
        }

        $payment->setAdditionalInformation(self::PAYPAL_ORDER_ID, (string) $answer['id']);
        $payment->setAdditionalInformation(Placeorder::REDIRECT_URL, $approveUrl);
        $order->setCanSendNewEmailFlag(false);
        $stateObject->setState(Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus(Order::STATE_PENDING_PAYMENT);
        $stateObject->setIsNotified(false);

        return $this;
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount In the base currency
     * @return $this
     * @throws LocalizedException
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $captureId = (string) $payment->getAdditionalInformation(self::CAPTURE_ID);
        if ($captureId === '') {
            throw new LocalizedException(__('This order has no PayPal payment to refund.'));
        }
        /** @var Order $order */
        $order = $payment->getOrder();
        $refund = $this->api->refundCapture(
            $captureId,
            self::amount($order, (float) $amount * (float) ($order->getBaseToOrderRate() ?: 1)),
            (int) $order->getStoreId()
        );
        $payment->setTransactionId((string) ($refund['id'] ?? $captureId . '-refund'))->setIsTransactionClosed(true);

        return $this;
    }

    /**
     * An amount in the order's currency, as PayPal takes it.
     *
     * @return array{currency_code: string, value: string}
     */
    public static function amount(Order $order, float $amount): array
    {
        $currency = (string) $order->getOrderCurrencyCode();

        return ['currency_code' => $currency, 'value' => Api::value($amount, $currency)];
    }
}
