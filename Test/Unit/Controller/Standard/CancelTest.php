<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Test\Unit\Controller\Standard;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\TestFramework\Unit\Helper\MockCreationTrait;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Checkout\Model\RedirectPayment\OrderLock;
use Zero\Checkout\Test\Unit\Fake\FakeLockManager;
use Zero\Checkout\Test\Unit\Fake\OrderLockBuilder;
use Zero\Paypal\Controller\Standard\Cancel;

/**
 * A3-15: cancelling on PayPal (classic redirect) cancels the session's last
 * order and restores the cart only under the order's lock, only for a
 * zero_paypal_standard order still waiting for payment that can be
 * cancelled, read again once locked; anything else goes back to the
 * checkout page untouched, with no message.
 */
class CancelTest extends TestCase
{
    use MockCreationTrait;
    use OrderLockBuilder;

    private FakeLockManager $locks;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private CheckoutSession&MockObject $session;
    /** @var string[] */
    private array $notices = [];
    private ?string $path = null;

    protected function setUp(): void
    {
        $this->locks = new FakeLockManager();
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->session = $this->createPartialMockWithReflection(CheckoutSession::class, ['getLastRealOrder', 'restoreQuote']);
    }

    public function testCancelsPendingPaypalStandardOrder(): void
    {
        $order = $this->order(Cancel::METHOD, Order::STATE_PENDING_PAYMENT, true);
        $order->expects($this->once())->method('registerCancellation')->with('Cancelled by customer at PayPal.');
        $this->orderRepository->expects($this->once())->method('save')->with($order);
        $this->session->expects($this->once())->method('restoreQuote')->willReturn(true);

        $this->execute($order);

        $this->assertSame('zero/checkout/index', $this->path);
        $this->assertSame(['Your PayPal payment was cancelled. Your cart is unchanged.'], $this->notices);
        $this->assertSame([['lock', OrderLock::PREFIX . '000000042', 10]], $this->locks->locks());
        $this->assertCount(1, $this->locks->unlocks());
    }

    public function testNewStateCancelsToo(): void
    {
        $order = $this->order(Cancel::METHOD, Order::STATE_NEW, true);
        $order->expects($this->once())->method('registerCancellation');
        $this->orderRepository->expects($this->once())->method('save');
        $this->session->expects($this->once())->method('restoreQuote');

        $this->execute($order);
    }

    /**
     * The IPN came first: the session still has the pending order, the reloaded one is processing.
     */
    public function testLeavesAnOrderPaidMeanwhileAlone(): void
    {
        $stale = $this->order(Cancel::METHOD, Order::STATE_PENDING_PAYMENT, true);
        $fresh = $this->order(Cancel::METHOD, Order::STATE_PROCESSING, false);
        $stale->expects($this->never())->method('registerCancellation');
        $fresh->expects($this->never())->method('registerCancellation');
        $this->expectNothingChanged();

        $this->execute($stale, $fresh);

        $this->assertSame('zero/checkout/index', $this->path);
        $this->assertSame([], $this->notices);
    }

    public function testLeavesOtherMethodsOrInvoicedOrdersAlone(): void
    {
        $other = $this->order('checkmo', Order::STATE_PENDING_PAYMENT, true);
        $other->expects($this->never())->method('registerCancellation');
        $this->expectNothingChanged();

        $this->execute($other);

        $this->assertSame([], $this->notices);
    }

    public function testLeavesAnOrderThatCannotBeCancelledAlone(): void
    {
        $order = $this->order(Cancel::METHOD, Order::STATE_NEW, false);
        $order->expects($this->never())->method('registerCancellation');
        $this->expectNothingChanged();

        $this->execute($order);

        $this->assertSame([], $this->notices);
    }

    public function testLockHeldByAnotherProcessChangesNothing(): void
    {
        $this->locks->holdByOther(OrderLock::PREFIX . '000000042');
        $order = $this->order(Cancel::METHOD, Order::STATE_PENDING_PAYMENT, true);
        $order->expects($this->never())->method('registerCancellation');
        $this->expectNothingChanged();

        $this->execute($order);

        $this->assertSame('zero/checkout/index', $this->path);
        $this->assertSame([], $this->notices);
    }

    public function testNoOrderInTheSession(): void
    {
        $order = $this->order(Cancel::METHOD, Order::STATE_PENDING_PAYMENT, true, 0);
        $order->expects($this->never())->method('registerCancellation');
        $this->expectNothingChanged();

        $this->execute($order);

        $this->assertSame([], $this->locks->locks());
        $this->assertSame('zero/checkout/index', $this->path);
    }

    private function expectNothingChanged(): void
    {
        $this->orderRepository->expects($this->never())->method('save');
        $this->session->expects($this->never())->method('restoreQuote');
    }

    private function execute(Order $order, ?Order $fresh = null): void
    {
        $this->session->method('getLastRealOrder')->willReturn($order);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use ($redirect) {
            $this->path = $path;
            return $redirect;
        });
        $resultFactory = $this->createStub(ResultFactory::class);
        $resultFactory->method('create')->willReturn($redirect);
        $messages = $this->createStub(MessageManager::class);
        $messages->method('addNoticeMessage')->willReturnCallback(function ($message) use ($messages) {
            $this->notices[] = (string) $message;
            return $messages;
        });

        (new Cancel(
            $resultFactory,
            $this->session,
            $this->orderLock($this->locks, $fresh ? [42 => $fresh] : []),
            $this->orderRepository,
            $messages
        ))->execute();
    }

    private function order(string $method, string $state, bool $canCancel, int $id = 42): Order&MockObject
    {
        $payment = $this->createStub(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id ?: null);
        $order->method('getIncrementId')->willReturn('000000042');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getState')->willReturn($state);
        $order->method('canCancel')->willReturn($canCancel);

        return $order;
    }
}
