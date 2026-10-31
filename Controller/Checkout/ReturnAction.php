<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Controller\Checkout;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Paypal\Model\Checkout\OrderUpdater;
use Zero\Paypal\Model\Checkout\Payment;

/**
 * PayPal Checkout's return URL (?order=<increment id>&token=<PayPal order>):
 * the buyer approved; the money is captured now (OrderUpdater), under the
 * order's lock (ReturnFlow), and an order PayPal says was never approved is
 * cancelled. Then ReturnResult: the success page, or the checkout page with
 * the cart restored.
 */
class ReturnAction implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater,
        private readonly ReturnFlow $returnFlow
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        $orderParam = $this->request->getParam('order');
        $order = $this->returnResult->lastOrder(Payment::CODE, is_scalar($orderParam) ? (string) $orderParam : '');
        $token = $this->request->getParam('token');
        if (!$order
            || $order->getPayment()->getAdditionalInformation(Payment::PAYPAL_ORDER_ID) !== (is_scalar($token) ? (string) $token : '')
        ) {
            return $this->returnResult->elsewhere();
        }
        $state = $this->returnFlow->run(
            $order,
            fn (Order $fresh): string => $this->orderUpdater->capture($fresh),
            fn (Order $fresh) => $this->orderUpdater->cancel($fresh)
        );

        return $this->returnResult->redirect($state, 'PayPal');
    }
}
