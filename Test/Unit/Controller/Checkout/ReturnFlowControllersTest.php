<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Test\Unit\Controller\Checkout;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Checkout\Model\RedirectPayment\OrderLock;
use Zero\Checkout\Model\RedirectPayment\OrderPayment;
use Zero\Checkout\Model\RedirectPayment\ReturnFlow;
use Zero\Checkout\Model\RedirectPayment\ReturnResult;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;
use Zero\Paypal\Controller\Checkout\Cancel;
use Zero\Paypal\Controller\Checkout\ReturnAction;
use Zero\Paypal\Model\Checkout\OrderUpdater;
use Zero\Paypal\Model\Checkout\Payment;

/**
 * PayPal Checkout's return and cancel URLs settle the order under its lock
 * (ReturnFlow): Return captures and cancels only when PayPal says it was
 * never approved; Cancel cancels only an order not settled meanwhile.
 */
class ReturnFlowControllersTest extends TestCase
{
    use OrderLockBuilder;

    private OrderUpdater&MockObject $orderUpdater;
    private ReturnResult&MockObject $returnResult;
    private FakeLockManager $locks;
    private Order $order;
    /** @var array<string, string> */
    private array $params = ['order' => '000000042', 'token' => 'PP-1'];

    protected function setUp(): void
    {
        $this->orderUpdater = $this->createMock(OrderUpdater::class);
        $this->returnResult = $this->createMock(ReturnResult::class);
        $this->locks = new FakeLockManager();
        $payment = $this->createStub(OrderPaymentInfo::class);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::PAYPAL_ORDER_ID, 'PP-1']]);
        $order = $this->createStub(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn(Order::STATE_PENDING_PAYMENT);
        $this->order = $order;
    }

    public function testReturnCapturesUnderTheLock(): void
    {
        $this->returnResult->method('lastOrder')->with(Payment::CODE, '000000042')->willReturn($this->order);
        $this->orderUpdater->expects($this->once())->method('capture')->with($this->order)
            ->willReturnCallback(function () {
                $this->assertTrue($this->locks->isLocked(OrderLock::PREFIX . '000000042'));
                return ReturnResult::PAID;
            });
        $this->orderUpdater->expects($this->never())->method('cancel');
        $this->expectRedirect(ReturnResult::PAID);

        $this->returnAction()->execute();
    }

    public function testReturnNeverApprovedIsCancelled(): void
    {
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $this->orderUpdater->expects($this->once())->method('capture')->willReturn(ReturnResult::NOT_STARTED);
        $this->orderUpdater->expects($this->once())->method('cancel')->with($this->order);
        $this->expectRedirect(ReturnResult::NOT_STARTED);

        $this->returnAction()->execute();
    }

    public function testReturnWithAnotherTokenGoesElsewhere(): void
    {
        $this->params['token'] = 'PP-OTHER';
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $this->orderUpdater->expects($this->never())->method('capture');
        $this->returnResult->expects($this->once())->method('elsewhere')->willReturn($this->createStub(Redirect::class));

        $this->returnAction()->execute();
    }

    public function testReturnWhileLockedIsPendingWithoutCapturing(): void
    {
        $this->locks->holdByOther(OrderLock::PREFIX . '000000042');
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $this->orderUpdater->expects($this->never())->method('capture');
        $this->expectRedirect(ReturnResult::PENDING);

        $this->returnAction()->execute();
    }

    public function testCancelOfAWaitingOrderCancelsIt(): void
    {
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $orderPayment = $this->createMock(OrderPayment::class);
        $orderPayment->expects($this->once())->method('settled')->with($this->order)->willReturn(null);
        $this->orderUpdater->expects($this->once())->method('cancel')->with($this->order);
        $this->expectRedirect(ReturnResult::NOT_STARTED);

        $this->cancel($orderPayment)->execute();
    }

    public function testCancelOfAnOrderPaidMeanwhileLeavesIt(): void
    {
        $this->returnResult->method('lastOrder')->willReturn($this->order);
        $orderPayment = $this->createMock(OrderPayment::class);
        $orderPayment->expects($this->once())->method('settled')->willReturn(ReturnResult::PAID);
        $this->orderUpdater->expects($this->never())->method('cancel');
        $this->expectRedirect(ReturnResult::PAID);

        $this->cancel($orderPayment)->execute();
    }

    private function expectRedirect(string $state): void
    {
        $this->returnResult->expects($this->once())->method('redirect')->with($state, 'PayPal')
            ->willReturn($this->createStub(Redirect::class));
    }

    private function request(): RequestInterface
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $this->params[$name] ?? null);

        return $request;
    }

    private function flow(): ReturnFlow
    {
        return new ReturnFlow($this->orderLock($this->locks), $this->createStub(LoggerInterface::class));
    }

    private function returnAction(): ReturnAction
    {
        return new ReturnAction($this->request(), $this->returnResult, $this->orderUpdater, $this->flow());
    }

    private function cancel(OrderPayment $orderPayment): Cancel
    {
        return new Cancel($this->request(), $this->returnResult, $this->orderUpdater, $orderPayment, $this->flow());
    }
}
