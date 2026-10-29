<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Model\Checkout;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\OrderUpdaterInterface;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;

/**
 * Back from PayPal: captures the approved order and follows the answer:
 * COMPLETED for the amount: paid; PENDING (review): nothing yet, commented;
 * declined: cancelled; not approved: NOT_STARTED. A second capture gets
 * ORDER_ALREADY_CAPTURED and reads the order instead.
 */
class OrderUpdater implements OrderUpdaterInterface
{
    public function __construct(
        private readonly Api $api,
        private readonly OrderPayment $orderPayment
    ) {
    }

    /**
     * @return string A ReturnResult state
     * @throws LocalizedException When PayPal doesn't answer
     */
    /**
     * The customer is back from PayPal (a headless storefront's zeroPaymentReturn):
     * what the Return and Cancel controllers do for the theme, by the outcome.
     *
     * @inheritdoc
     */
    public function update(Order $order, array $params = []): string
    {
        if (($params['outcome'] ?? '') === 'cancel') {
            $this->cancel($order);

            return ReturnResult::FAILED;
        }
        $state = $this->capture($order);
        if ($state === ReturnResult::NOT_STARTED) {
            $this->cancel($order);
        }

        return $state;
    }

    public function capture(Order $order): string
    {
        $paypalOrderId = (string) $order->getPayment()?->getAdditionalInformation(Payment::PAYPAL_ORDER_ID);
        if ($order->getPayment()?->getMethod() !== Payment::CODE || $paypalOrderId === '') {
            return ReturnResult::PENDING;
        }
        $settled = $this->orderPayment->settled($order);
        if ($settled !== null) {
            return $settled;
        }
        $storeId = (int) $order->getStoreId();
        $answer = $this->api->captureOrder($paypalOrderId, $storeId);
        if (isset($answer['name'])) {
            $issue = (string) ($answer['details'][0]['issue'] ?? $answer['name']);
            switch ($issue) {
                case 'ORDER_ALREADY_CAPTURED':
                    $answer = $this->api->getOrder($paypalOrderId, $storeId);
                    break;
                case 'ORDER_NOT_APPROVED':
                    return ReturnResult::NOT_STARTED;
                case 'INSTRUMENT_DECLINED':
                case 'PAYER_ACTION_REQUIRED':
                case 'TRANSACTION_REFUSED':
                    return $this->failed($order, $issue);
                default:
                    throw new LocalizedException(__('PayPal refused the request: %1', $issue));
            }
        }
        $capture = (array) ($answer['purchase_units'][0]['payments']['captures'][0] ?? []);

        return match ((string) ($capture['status'] ?? '')) {
            'COMPLETED' => $this->paid($order, $capture),
            'PENDING' => $this->pending($order, (string) ($capture['status_details']['reason'] ?? '')),
            'DECLINED', 'FAILED' => $this->failed($order, (string) $capture['status']),
            default => ReturnResult::PENDING,
        };
    }

    /**
     * The buyer cancelled on PayPal: the order is cancelled.
     */
    public function cancel(Order $order): void
    {
        if ($this->orderPayment->settled($order) === null) {
            $this->failed($order, (string) __('the customer cancelled on PayPal'));
        }
    }

    private function paid(Order $order, array $capture): string
    {
        $expected = Payment::amount($order, (float) $order->getGrandTotal());
        if (($capture['amount'] ?? null) != $expected) {
            $this->orderPayment->flag(
                $order,
                (string) __('PayPal reports a payment of a different amount; check it in the PayPal account.'),
                sprintf(
                    'PayPal: %s %s, expected %s %s',
                    $capture['amount']['value'] ?? '?',
                    $capture['amount']['currency_code'] ?? '?',
                    $expected['value'],
                    $expected['currency_code']
                )
            );

            return ReturnResult::PENDING;
        }
        $order->getPayment()->setAdditionalInformation(Payment::CAPTURE_ID, (string) $capture['id']);
        $this->orderPayment->capture($order, (string) $capture['id']);

        return ReturnResult::PAID;
    }

    private function pending(Order $order, string $reason): string
    {
        $this->orderPayment->flag(
            $order,
            (string) __('PayPal holds the payment for review (%1); check it in the PayPal account.', $reason),
            'PayPal capture pending: ' . $reason
        );

        return ReturnResult::PENDING;
    }

    private function failed(Order $order, string $reason): string
    {
        $this->orderPayment->cancel($order, (string) __('PayPal payment did not go through: %1', $reason));

        return ReturnResult::FAILED;
    }
}
