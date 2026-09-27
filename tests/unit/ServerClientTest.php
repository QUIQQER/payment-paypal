<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Api\ServerClient;
use QUI\ERP\Payments\PayPal\Api\ResponseException;
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
            ->willReturn($this->createResponse('', 204));

        self::assertNull(
            $this->createClient($Orders)->patchOrder('ORDER-3', $body)
        );
    }

    public function testCaptureOrderOmitsEmptyOptionalBody(): void
    {
        $Orders = $this->createMock(OrdersController::class);
        $Orders->expects(self::once())
            ->method('captureOrder')
            ->with([
                'id' => 'ORDER-4',
                'prefer' => 'return=representation'
            ])
            ->willReturn($this->createResponse(['status' => 'COMPLETED']));

        self::assertSame(
            ['status' => 'COMPLETED'],
            $this->createClient($Orders)->captureOrder('ORDER-4', [])
        );
    }

    public function testCaptureOrderPassesNonEmptyBody(): void
    {
        $body = ['payment_source' => ['token' => ['id' => 'TOKEN-1']]];
        $Orders = $this->createMock(OrdersController::class);
        $Orders->expects(self::once())
            ->method('captureOrder')
            ->with([
                'id' => 'ORDER-5',
                'prefer' => 'return=representation',
                'body' => $body
            ])
            ->willReturn($this->createResponse(['status' => 'COMPLETED']));

        self::assertSame(
            ['status' => 'COMPLETED'],
            $this->createClient($Orders)->captureOrder('ORDER-5', $body)
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

    private function createResponse(mixed $result, int $status = 200): ApiResponse
    {
        $Response = $this->createMock(ApiResponse::class);
        $Response->method('getStatusCode')->willReturn($status);

        if ($result === null) {
            $Response->method('getResult')->willReturn(null);
            return $Response;
        }

        if (!is_array($result)) {
            $Response->method('getResult')->willReturn($result);
            return $Response;
        }

        $object = new stdClass();

        foreach ($result as $key => $value) {
            $object->{$key} = $value;
        }

        $Response->method('getResult')->willReturn($object);

        return $Response;
    }

    public function testHtmlHttpFailurePreservesStatusAndDebugId(): void
    {
        $Response = $this->createMock(ApiResponse::class);
        $Response->method('getStatusCode')->willReturn(503);
        $Response->method('getHeaders')->willReturn(['PayPal-Debug-Id' => 'abcdef1234567']);
        $Response->method('getBody')->willReturn('<html>private upstream error</html>');
        $Response->expects(self::never())->method('getResult');
        $Orders = $this->createMock(OrdersController::class);
        $Orders->method('getOrder')->willReturn($Response);

        try {
            $this->createClient($Orders)->getOrder('ORDER-FAIL');
            self::fail('HTTP errors must not become normal results.');
        } catch (ResponseException $Error) {
            self::assertSame(503, $Error->getCode());
            self::assertSame([
                'httpStatus' => 503,
                'responseType' => 'non-json',
                'debugId' => 'abcdef1234567'
            ], $Error->getDiagnostics());
            self::assertStringNotContainsString('private', $Error->getMessage());
        }
    }

    public function testStructuredHttpFailureIsRejectedBeforeResultNormalization(): void
    {
        $Response = $this->createMock(ApiResponse::class);
        $Response->method('getStatusCode')->willReturn(422);
        $Response->method('getBody')->willReturn(json_encode([
            'name' => 'UNPROCESSABLE_ENTITY',
            'debug_id' => 'abcdef1234567',
            'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED', 'value' => 'private@example.com']]
        ]));
        $Response->expects(self::never())->method('getResult');
        $Orders = $this->createMock(OrdersController::class);
        $Orders->method('captureOrder')->willReturn($Response);

        try {
            $this->createClient($Orders)->captureOrder('ORDER-FAIL', []);
            self::fail('Structured API errors must not be returned as successful results.');
        } catch (ResponseException $Error) {
            self::assertSame('UNPROCESSABLE_ENTITY', $Error->getDiagnostics()['paypalError']);
            self::assertSame(['PAYEE_ACCOUNT_RESTRICTED'], $Error->getDiagnostics()['paypalIssues']);
            self::assertStringNotContainsString('private', json_encode($Error->getDiagnostics()));
        }
    }

    public function testFailedPatchIsNotTreatedAsSuccessfulNoContent(): void
    {
        $Response = $this->createMock(ApiResponse::class);
        $Response->method('getStatusCode')->willReturn(500);
        $Orders = $this->createMock(OrdersController::class);
        $Orders->method('patchOrder')->willReturn($Response);
        $this->expectException(ResponseException::class);
        $this->createClient($Orders)->patchOrder('ORDER-FAIL', []);
    }

    public function testInvalidSuccessfulResponseRetainsHttpContext(): void
    {
        $Response = $this->createResponse('unexpected text');
        $Orders = $this->createMock(OrdersController::class);
        $Orders->method('getOrder')->willReturn($Response);

        try {
            $this->createClient($Orders)->getOrder('ORDER-INVALID');
            self::fail('Invalid response must throw.');
        } catch (ResponseException $Error) {
            self::assertSame(200, $Error->getDiagnostics()['httpStatus']);
        }
    }
}
