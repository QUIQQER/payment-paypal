<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\MCP\Operations;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\ERP\Payments\PayPal\Recurring\Payment as RecurringPayment;
use ReflectionProperty;
use RuntimeException;

final class McpBillingPlansTest extends TestCase
{
    private mixed $originalPayment;

    protected function setUp(): void
    {
        $this->originalPayment = (new ReflectionProperty(BillingPlans::class, 'Payment'))->getValue();
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(BillingPlans::class, 'Payment'))->setValue(null, $this->originalPayment);
    }

    public function testPaginationConvertsOneBasedMcpPageToLegacyApiPage(): void
    {
        $Payment = $this->createMock(Payment::class);
        $Payment->expects(self::once())->method('payPalApiRequest')->with(
            RecurringPayment::PAYPAL_REQUEST_TYPE_LIST_BILLING_PLANS,
            [],
            ['page' => 2, 'page_size' => 20, 'status' => 'ACTIVE', 'total_required' => 'yes']
        )->willReturn(['plans' => [['id' => 'P-TEST1']], 'total_items' => 45]);
        (new ReflectionProperty(BillingPlans::class, 'Payment'))->setValue(null, $Payment);

        self::assertSame([
            'plans' => [['id' => 'P-TEST1']],
            'count' => 45,
            'page' => 3,
            'perPage' => 20
        ], (new Operations())->billingPlans(3, 20));
    }

    public function testLegacyApiFailureIsNotReportedAsAnEmptySuccessfulList(): void
    {
        $Payment = $this->createMock(Payment::class);
        $Payment->method('payPalApiRequest')->willReturn(false);
        (new ReflectionProperty(BillingPlans::class, 'Payment'))->setValue(null, $Payment);
        $this->expectException(RuntimeException::class);
        (new Operations())->billingPlans(1, 10);
    }

    public function testDeletionUsesTheExistingLegacyApi(): void
    {
        $Payment = $this->createMock(Payment::class);
        $Payment->expects(self::once())->method('payPalApiRequest')->with(
            RecurringPayment::PAYPAL_REQUEST_TYPE_UPDATE_BILLING_PLAN,
            [['op' => 'replace', 'path' => '/', 'value' => ['state' => 'DELETED']]],
            [RecurringPayment::ATTR_PAYPAL_BILLING_PLAN_ID => 'P-TEST1']
        )->willReturn(null);
        (new ReflectionProperty(BillingPlans::class, 'Payment'))->setValue(null, $Payment);
        (new Operations())->deleteBillingPlan('P-TEST1');
    }
}
