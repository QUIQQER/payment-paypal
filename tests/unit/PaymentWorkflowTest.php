<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\OrderDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PaymentWorkflowDouble;

final class PaymentWorkflowTest extends TestCase
{
    public function testUpdateStopsWhenOrderWasNotCreatedAtPayPal(): void
    {
        $Order = new OrderDouble();
        $Payment = new PaymentWorkflowDouble();

        $Payment->updatePayPalOrder($Order);

        self::assertSame([], $Payment->apiCalls);
        self::assertSame(0, $Payment->saveCount);
        self::assertContains(
            'PayPal :: Order cannot be updated since it has not been created yet',
            $Order->history
        );
    }

    public function testUpdateSendsPurchaseUnitPatchAndSavesOrder(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-1');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = [
            'purchase_units' => [
                ['reference_id' => $Order->getUUID(), 'amount' => ['value' => '12.00']]
            ]
        ];

        $Payment->updatePayPalOrder($Order);

        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_UPDATE_ORDER, $Payment->apiCalls[0]['request']);
        self::assertSame([
            [
                'op' => 'replace',
                'path' => '/purchase_units/@reference_id==\'' . $Order->getUUID() . '\'',
                'value' => $Payment->payPalData['purchase_units'][0]
            ]
        ], $Payment->apiCalls[0]['body']);
        self::assertSame(1, $Payment->saveCount);
        self::assertContains('PayPal :: Order successfully updated', $Order->history);
    }

    public function testUpdateSavesOrderAndPropagatesApiFailure(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-2');
        $Payment = new PaymentWorkflowDouble();
        $Payment->payPalData = ['purchase_units' => [[]]];
        $Payment->apiException = new PayPalException('Update failed');

        try {
            $Payment->updatePayPalOrder($Order);
            self::fail('PayPalException was not propagated.');
        } catch (PayPalException $Exception) {
            self::assertSame($Payment->apiException, $Exception);
        }

        self::assertSame(1, $Payment->saveCount);
        self::assertContains(
            'PayPal :: PayPal API ERROR. Please check error logs.',
            $Order->history
        );
    }

    public function testOrderDetailsRequireStoredOrderId(): void
    {
        $Payment = new PaymentWorkflowDouble();

        self::assertFalse(
            $Payment->fetchPayPalOrderDetails(new OrderDouble())
        );
        self::assertSame([], $Payment->apiCalls);
    }

    public function testOrderDetailsReturnApiResponse(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-3');
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiResponse = ['id' => 'ORDER-3', 'status' => 'APPROVED'];

        self::assertSame(
            $Payment->apiResponse,
            $Payment->fetchPayPalOrderDetails($Order)
        );
        self::assertSame(Payment::PAYPAL_REQUEST_TYPE_GET_ORDER, $Payment->apiCalls[0]['request']);
    }

    public function testOrderDetailsReturnFalseAfterApiFailure(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-4');
        $Payment = new PaymentWorkflowDouble();
        $Payment->apiException = new PayPalException('Lookup failed');

        self::assertFalse($Payment->fetchPayPalOrderDetails($Order));
    }

    public function testVoidHandlesMissingAndExistingOrderIds(): void
    {
        $MissingOrder = new OrderDouble();
        $Payment = new PaymentWorkflowDouble();

        $Payment->voidOrder($MissingOrder);
        self::assertSame(1, $Payment->saveCount);
        self::assertContains(
            'PayPal :: Order cannot be voided because it has not been created yet or was voided before',
            $MissingOrder->history
        );

        $ExistingOrder = new OrderDouble();
        $ExistingOrder->setPaymentData(Payment::ATTR_PAYPAL_ORDER_ID, 'ORDER-5');
        $Payment->voidOrder($ExistingOrder);
        self::assertSame(2, $Payment->saveCount);
        self::assertContains('PayPal :: Order voided.', $ExistingOrder->history);
    }

    public function testAlreadyAuthorizedOrderStopsBeforeApiRequest(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_AUTHORIZATION_ID, 'AUTH-1');
        $Payment = new PaymentWorkflowDouble();

        $Payment->authorizePayPalOrder($Order);

        self::assertSame([], $Payment->apiCalls);
        self::assertSame(1, $Payment->saveCount);
    }

    public function testAlreadyCapturedOrderStopsBeforeApiRequest(): void
    {
        $Order = new OrderDouble();
        $Order->setPaymentData(Payment::ATTR_PAYPAL_PAYMENT_SUCCESSFUL, true);
        $Payment = new PaymentWorkflowDouble();

        $Payment->capturePayPalOrder($Order);

        self::assertSame([], $Payment->apiCalls);
        self::assertSame(1, $Payment->saveCount);
    }
}
