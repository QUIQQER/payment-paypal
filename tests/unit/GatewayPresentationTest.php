<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Order\Controls\OrderProcess\Processing;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use QUI\ERP\User;

final class GatewayPresentationTest extends TestCase
{
    public function testOneTimeGatewayDisplayConfiguresProcessingStep(): void
    {
        $Sum = $this->createMock(CalculationValue::class);
        $Sum->method('formatted')->willReturn('12,34 €');
        $Calculations = $this->createMock(Calculations::class);
        $Calculations->method('getSum')->willReturn($Sum);
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getPriceCalculation')->willReturn($Calculations);
        $Order->method('getUUID')->willReturn('GATEWAY-ORDER');
        $Order->method('isSuccessful')->willReturn(0);
        $Step = $this->processingStep();

        self::assertNotSame(
            '',
            (new Payment())->getGatewayDisplay($Order, $Step)
        );
    }

    public function testExpressGatewayDisplayConfiguresProcessingStep(): void
    {
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getHash')->willReturn('EXPRESS-GATEWAY');

        self::assertNotSame(
            '',
            (new PaymentExpress())->getGatewayDisplay(
                $Order,
                $this->processingStep()
            )
        );
    }

    public function testRecurringGatewayDisplayConfiguresProcessingStep(): void
    {
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getHash')->willReturn('RECURRING-GATEWAY');

        self::assertNotSame(
            '',
            (new RecurringPayment())->getGatewayDisplay(
                $Order,
                $this->processingStep()
            )
        );
    }

    public function testRecurringInvoiceTextUsesCustomerLocale(): void
    {
        $Customer = $this->createMock(User::class);
        $Customer->method('getLocale')->willReturn(QUI::getLocale());
        $Invoice = $this->createMock(Invoice::class);
        $Invoice->method('getCustomer')->willReturn($Customer);

        self::assertNotSame(
            '',
            (new RecurringPayment())->getInvoiceInformationText($Invoice)
        );
    }

    private function processingStep(): Processing
    {
        $Step = $this->createMock(Processing::class);
        $Step->expects(self::once())->method('setTitle');
        $Step->expects(self::once())->method('setContent');

        return $Step;
    }
}
