<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Paypal\Test\Unit\Model\Checkout;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment as OrderPaymentInfo;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Paypal\Model\Checkout\Api;
use Zero\Paypal\Model\Checkout\Payment;

/**
 * A3-5: a PayPal Checkout refund is the credit memo's amount in the order
 * currency, sent with a PayPal-Request-Id from RefundKey (the same for a
 * retry of the memo, another for the next partial refund).
 *
 * ObjectManager's helper builds the method's parent dependencies as mocks.
 */
#[AllowMockObjectsWithoutExpectations]
class PaymentTest extends TestCase
{
    private Api&MockObject $api;
    private Payment $method;

    protected function setUp(): void
    {
        (new ObjectManager($this))->prepareObjectManager([
            [DirectoryHelper::class, $this->createStub(DirectoryHelper::class)],
        ]);
        $this->api = $this->createMock(Api::class);
        $this->method = (new ObjectManager($this))->getObject(Payment::class, ['api' => $this->api]);
    }

    public function testRefundUsesRefundKeyAndOrderCurrency(): void
    {
        $payment = $this->payment('CAP-1', 8.0, 0.0);
        $this->api->expects($this->once())->method('refundCapture')
            ->with('CAP-1', ['currency_code' => 'EUR', 'value' => '8.00'], 1, 'zero-000000123-r0-1000')
            ->willReturn(['id' => 'REF-1', 'status' => 'COMPLETED']);
        $payment->expects($this->once())->method('setTransactionId')->with('REF-1')->willReturnSelf();
        $payment->expects($this->once())->method('setIsTransactionClosed')->with(true)->willReturnSelf();

        $this->method->refund($payment, 10.0);
    }

    public function testSecondPartialRefundGetsAnotherRequestId(): void
    {
        $payment = $this->payment('CAP-1', 4.0, 5.0);
        $payment->method('setTransactionId')->willReturnSelf();
        $this->api->expects($this->once())->method('refundCapture')
            ->with('CAP-1', ['currency_code' => 'EUR', 'value' => '4.00'], 1, 'zero-000000123-r500-500')
            ->willReturn(['id' => 'REF-2']);

        $this->method->refund($payment, 5.0);
    }

    public function testRefundWithoutCreditmemoUsesBaseTimesRate(): void
    {
        $payment = $this->payment('CAP-1', null, 0.0);
        $payment->method('setTransactionId')->willReturnSelf();
        $this->api->expects($this->once())->method('refundCapture')
            ->with('CAP-1', ['currency_code' => 'EUR', 'value' => '4.00'], 1, 'zero-000000123-r0-500')
            ->willReturn(['id' => 'REF-3']);

        $this->method->refund($payment, 5.0);
    }

    public function testRefundNeedsTheCapture(): void
    {
        $this->api->expects($this->never())->method('refundCapture');
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('This order has no PayPal payment to refund.');

        $this->method->refund($this->payment('', 8.0, 0.0), 10.0);
    }

    /**
     * Order 123: base USD, shown in EUR at 0.8.
     */
    private function payment(string $captureId, ?float $creditmemoGrandTotal, float $baseAmountRefunded): OrderPaymentInfo&MockObject
    {
        $order = $this->createStub(Order::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getBaseCurrencyCode')->willReturn('USD');
        $order->method('getOrderCurrencyCode')->willReturn('EUR');
        $order->method('getBaseToOrderRate')->willReturn(0.8);
        $payment = $this->createMock(OrderPaymentInfo::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getAdditionalInformation')->willReturnMap([[Payment::CAPTURE_ID, $captureId]]);
        $payment->method('getBaseAmountRefunded')->willReturn($baseAmountRefunded);
        if ($creditmemoGrandTotal !== null) {
            $creditmemo = $this->createStub(Creditmemo::class);
            $creditmemo->method('getGrandTotal')->willReturn($creditmemoGrandTotal);
            $payment->method('getCreditmemo')->willReturn($creditmemo);
        }

        return $payment;
    }
}
