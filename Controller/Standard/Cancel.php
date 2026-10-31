<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Controller\Standard;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Zero\Checkout\Model\RedirectPayment\OrderLock;
use Zero\Checkout\Model\RedirectPayment\OrderLockedException;

/**
 * Return URL for a customer who cancels on PayPal (classic redirect): the
 * checkout session's last order is cancelled and its quote reactivated, so
 * the cart is back exactly as it was. Only under the order's lock and only
 * when that order is a zero_paypal_standard order still waiting for its
 * payment (pending payment or new): an order paid meanwhile (the IPN came
 * first), another method's order, or one another process holds, is left
 * alone, and the customer just goes back to the checkout page.
 */
class Cancel implements HttpGetActionInterface, CsrfAwareActionInterface
{
    public const METHOD = 'zero_paypal_standard';

    public function __construct(
        private readonly ResultFactory $resultFactory,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderLock $orderLock,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly MessageManager $messageManager
    ) {
    }

    /**
     * @return Redirect
     */
    public function execute()
    {
        /** @var Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $resultRedirect->setPath('zero/checkout/index');
        $order = $this->checkoutSession->getLastRealOrder();
        if (!$order->getId()) {
            return $resultRedirect;
        }
        try {
            $cancelled = $this->orderLock->run($order, fn (Order $fresh): bool => $this->cancel($fresh));
        } catch (OrderLockedException) {
            $cancelled = false;
        }
        if ($cancelled) {
            $this->messageManager->addNoticeMessage(
                __('Your PayPal payment was cancelled. Your cart is unchanged.')
            );
        }

        return $resultRedirect;
    }

    private function cancel(Order $order): bool
    {
        if ($order->getPayment()?->getMethod() !== self::METHOD
            || !in_array($order->getState(), [Order::STATE_PENDING_PAYMENT, Order::STATE_NEW], true)
            || !$order->canCancel()
        ) {
            return false;
        }
        $order->registerCancellation((string) __('Cancelled by customer at PayPal.'));
        $this->orderRepository->save($order);
        $this->checkoutSession->restoreQuote();

        return true;
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
