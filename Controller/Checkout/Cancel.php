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
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Paypal\Model\Checkout\OrderUpdater;
use Zero\Paypal\Model\Checkout\Payment;

/**
 * PayPal Checkout's cancel URL (?order=<increment id>): the buyer cancelled on
 * PayPal. Under the order's lock (ReturnFlow): an order still waiting is
 * cancelled and the cart restored; one already settled meanwhile (paid in
 * another tab, or cancelled) is left as it is and the customer goes where
 * its state says.
 */
class Cancel implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ReturnResult $returnResult,
        private readonly OrderUpdater $orderUpdater,
        private readonly OrderPayment $orderPayment,
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
        if (!$order) {
            return $this->returnResult->elsewhere();
        }
        $state = $this->returnFlow->run(
            $order,
            fn (Order $fresh): string => $this->orderPayment->settled($fresh) ?? ReturnResult::NOT_STARTED,
            fn (Order $fresh) => $this->orderUpdater->cancel($fresh)
        );

        return $this->returnResult->redirect($state, 'PayPal');
    }
}
