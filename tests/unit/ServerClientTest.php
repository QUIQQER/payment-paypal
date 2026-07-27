<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Api\ServerClient;
use stdClass;

final class ServerClientTest extends TestCase
{
    public function testClientCanBeBuiltForSandbox(): void
    {
        self::assertInstanceOf(
            ServerClient::class,
            new ServerClient('sandbox-client', 'sandbox-secret', true)
        );
    }

    public function testClientCanBeBuiltForProduction(): void
    {
        self::assertInstanceOf(
            ServerClient::class,
            new ServerClient('production-client', 'production-secret', false)
        );
    }

    public function testCreateOrderPassesBodyAndRepresentationPreference(): void
    {
        $body = ['intent' => 'CAPTURE'];
        $Orders = $this->createMock(OrdersController::class);
        $Orders->expects(self::once())
            ->method('createOrder')
            ->with([
                'body' => $body,
                'prefer' => 'return=representation'
            ])
            ->willReturn($this->createResponse([
                'id' => 'ORDER-1',
                'status' => 'CREATED'
            ]));

        $Client = $this->createClient($Orders);

        self::assertSame([
            'id' => 'ORDER-1',
            'status' => 'CREATED'
        ], $Client->createOrder($body));
    }

    public function testGetOrderPassesOrderId(): void
    {
        $Orders = $this->createMock(OrdersController::class);
        $Orders->expects(self::once())
            ->method('getOrder')
            ->with(['id' => 'ORDER-2'])
            ->willReturn($this->createResponse(['id' => 'ORDER-2']));

        self::assertSame(
            ['id' => 'ORDER-2'],
            $this->createClient($Orders)->getOrder('ORDER-2')
        );
    }

    public function testPatchOrderPassesOrderIdAndBody(): void
    {
        $body = [['op' => 'replace']];
        $Orders = $this->createMock(OrdersController::class);
        $Orders->expects(self::once())
            ->method('patchOrder')
            ->with([
                'id' => 'ORDER-3',
                'body' => $body
            ])
            ->willReturn($this->createResponse(null));

        self::assertNull(
            $this->createClient($Orders)->patchOrder('ORDER-3', $body)
        );
    }

    public function testCaptureOrderPassesBodyAndRepresentationPreference(): void
    {
        $Orders = $this->createMock(OrdersController::class);
        $Orders->expects(self::once())
            ->method('captureOrder')
            ->with([
                'id' => 'ORDER-4',
                'body' => [],
                'prefer' => 'return=representation'
            ])
            ->willReturn($this->createResponse(['status' => 'COMPLETED']));

        self::assertSame(
            ['status' => 'COMPLETED'],
            $this->createClient($Orders)->captureOrder('ORDER-4', [])
        );
    }

    public function testRefundPassesCaptureIdAndRepresentationPreference(): void
    {
        $body = [
            'amount' => [
                'value' => '5.00',
                'currency_code' => 'EUR'
            ]
        ];
        $Payments = $this->createMock(PaymentsController::class);
        $Payments->expects(self::once())
            ->method('refundCapturedPayment')
            ->with([
                'captureId' => 'CAPTURE-1',
                'body' => $body,
                'prefer' => 'return=representation'
            ])
            ->willReturn($this->createResponse([
                'id' => 'REFUND-1',
                'status' => 'COMPLETED'
            ]));

        $Sdk = $this->createMock(PaypalServerSdkClient::class);
        $Sdk->method('getPaymentsController')->willReturn($Payments);
        $Client = new ServerClient('', '', false, $Sdk);

        self::assertSame([
            'id' => 'REFUND-1',
            'status' => 'COMPLETED'
        ], $Client->refundCapturedPayment('CAPTURE-1', $body));
    }

    private function createClient(OrdersController $Orders): ServerClient
    {
        $Sdk = $this->createMock(PaypalServerSdkClient::class);
        $Sdk->method('getOrdersController')->willReturn($Orders);

        return new ServerClient('', '', false, $Sdk);
    }

    private function createResponse(?array $result): ApiResponse
    {
        $Response = $this->createMock(ApiResponse::class);

        if ($result === null) {
            $Response->method('getResult')->willReturn(null);
            return $Response;
        }

        $object = new stdClass();

        foreach ($result as $key => $value) {
            $object->{$key} = $value;
        }

        $Response->method('getResult')->willReturn($object);

        return $Response;
    }
}
