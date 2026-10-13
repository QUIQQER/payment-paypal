<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\ERP\Constants;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Api\ResponseException;
use QUI\ERP\Payments\PayPal\Diagnostics;
use QUI\ERP\Payments\PayPal\Settings;
use QUI\Log\Logger;
use PaypalServerSdkLib\Exceptions\ApiException;
use PaypalServerSdkLib\Http\HttpResponse;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalSystemException;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PayPalServerApiPaymentDouble;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\PayPalServerClientDouble;

final class DiagnosticsTest extends TestCase
{
    private TestHandler $Handler;
    private array $sessionValues = [];

    protected function setUp(): void
    {
        $this->Handler = new TestHandler();
        Logger::getLogger()->pushHandler($this->Handler);

        foreach (['paypalDiagnosticsToken', 'paypalDiagnosticsRate'] as $key) {
            $this->sessionValues[$key] = QUI::getSession()->get($key);
            QUI::getSession()->remove($key);
        }
    }

    protected function tearDown(): void
    {
        Logger::getLogger()->popHandler();

        foreach ($this->sessionValues as $key => $value) {
            QUI::getSession()->set($key, $value);
        }
    }

    public function testSdkConfigurationKeepsEnvironmentAndCredentialsTogether(): void
    {
        $Config = Settings::getConfig();
        $original = $Config->get('api', 'sandbox');

        try {
            foreach ([0, 1] as $sandbox) {
                $Config->setValue('api', 'sandbox', $sandbox);
                $result = Diagnostics::getSdkConfig();
                self::assertSame((bool)$sandbox, $result['sandbox']);
                self::assertSame($Config->get('api', $sandbox ? 'sandbox_client_id' : 'client_id'), $result['clientId']);
                self::assertArrayNotHasKey('clientSecret', $result);
            }
        } finally {
            $Config->setValue('api', 'sandbox', $original);
        }
    }

    public function testExplicitSdkEnvironmentDoesNotChangeActiveShopConfiguration(): void
    {
        $Config = Settings::getConfig();
        $original = $Config->get('api', 'sandbox');

        try {
            foreach ([0, 1] as $active) {
                $Config->setValue('api', 'sandbox', $active);

                foreach ([false, true] as $sandbox) {
                    $result = Diagnostics::getSdkConfig($sandbox);
                    self::assertSame($sandbox, $result['sandbox']);
                    self::assertSame(
                        $Config->get('api', $sandbox ? 'sandbox_client_id' : 'client_id'),
                        $result['clientId']
                    );
                    self::assertArrayNotHasKey('clientSecret', $result);
                    self::assertSame((bool)$active, (bool)$Config->get('api', 'sandbox'));
                }

                self::assertSame((bool)$active, Diagnostics::getSdkConfig()['sandbox']);
            }
        } finally {
            $Config->setValue('api', 'sandbox', $original);
        }
    }

    public function testFailedBrowserEligibilityProducesSafeUsefulLog(): void
    {
        $config = Diagnostics::getSdkConfig();
        $payload = json_encode([
            'operation' => 'findEligibleMethods',
            'reason' => 'missing_auth',
            'errorName' => 'SdkInitError',
            'reportedEnvironment' => 'sandbox',
            'sdkEnvironment' => 'sandbox',
            'httpStatus' => 401,
            'debugId' => 'abcdef1234567',
            'correlationId' => '11111111-2222-4333-8444-555555555555',
            'message' => 'secret-token private@example.com',
            'stack' => 'private URL',
            'clientId' => 'secret-client',
            'clientSecret' => 'secret-password'
        ], JSON_THROW_ON_ERROR);
        self::assertTrue(Diagnostics::logBrowserError($config['diagnosticsToken'], $payload));
        $records = $this->Handler->getRecords();
        $record = end($records);
        $context = $record['context'];
        self::assertSame('paypal_api', $record['extra']['quiqqer']['filename'] ?? $context['filename'] ?? null);
        self::assertSame('findEligibleMethods', $context['paypalOperation']);
        self::assertSame('missing_auth', $context['reason']);
        self::assertSame('SdkInitError', $context['errorName']);
        self::assertSame('abcdef1234567', $context['debugId']);
        self::assertSame(401, $context['httpStatus']);
        self::assertArrayHasKey('occurredAt', $context);
        self::assertStringNotContainsString('secret', json_encode($records));
        self::assertStringNotContainsString('private', json_encode($records));
        self::assertStringNotContainsString($config['diagnosticsToken'], json_encode($records));
    }

    public function testBrowserLogRejectsInvalidTokenAndLimitsReports(): void
    {
        $config = Diagnostics::getSdkConfig();
        $payload = '{"operation":"createInstance","reason":"operation_failed"}';
        self::assertFalse(Diagnostics::logBrowserError('wrong-token', $payload));
        self::assertFalse(Diagnostics::logBrowserError($config['diagnosticsToken'], str_repeat('a', 4097)));
        self::assertFalse(Diagnostics::logBrowserError($config['diagnosticsToken'], '{"operation":"private-data"}'));

        for ($i = 0; $i < 10; $i++) {
            self::assertTrue(Diagnostics::logBrowserError($config['diagnosticsToken'], $payload));
        }

        Diagnostics::getSdkConfig();
        self::assertFalse(Diagnostics::logBrowserError($config['diagnosticsToken'], $payload));
        self::assertCount(10, $this->Handler->getRecords());
    }

    public function testBrowserAuthorizationFailureIncludesPaypalCodesAndDebugId(): void
    {
        $config = Diagnostics::getSdkConfig();
        self::assertTrue(Diagnostics::logBrowserError($config['diagnosticsToken'], json_encode([
            'operation' => 'findEligibleMethods',
            'sdkErrorCode' => 'ERR_INIT_FIND_ELIGIBLE_METHODS',
            'paypalError' => 'NOT_AUTHORIZED',
            'paypalIssues' => ['NOT_AUTHORIZED', 'NOT_AUTHORIZED', 'private@example.com'],
            'httpStatus' => 403,
            'debugId' => '730b69995797f',
            'diagnosticHint' => 'private injected hint',
            'response' => ['message' => 'private response', 'token' => 'secret-token']
        ], JSON_THROW_ON_ERROR)));
        $context = $this->Handler->getRecords()[0]['context'];
        self::assertSame('ERR_INIT_FIND_ELIGIBLE_METHODS', $context['sdkErrorCode']);
        self::assertSame('NOT_AUTHORIZED', $context['paypalError']);
        self::assertSame(['NOT_AUTHORIZED'], $context['paypalIssues']);
        self::assertSame(403, $context['httpStatus']);
        self::assertSame('730b69995797f', $context['debugId']);
        self::assertArrayNotHasKey('diagnosticHint', $context);
        self::assertStringNotContainsString('private', json_encode($context));
        self::assertStringNotContainsString('secret', json_encode($context));
    }

    public function testOpaqueSdkEligibilityFailureIncludesTargetedDiagnosticHint(): void
    {
        $config = Diagnostics::getSdkConfig();
        self::assertTrue(Diagnostics::logBrowserError($config['diagnosticsToken'], json_encode([
            'operation' => 'findEligibleMethods',
            'errorName' => 'SdkInitError',
            'sdkErrorCode' => 'ERR_INIT_FIND_ELIGIBLE_METHODS'
        ], JSON_THROW_ON_ERROR)));
        $context = $this->Handler->getRecords()[0]['context'];
        self::assertStringContainsString('find-eligible-methods in browser Network', $context['diagnosticHint']);
        self::assertArrayNotHasKey('httpStatus', $context);
        self::assertArrayNotHasKey('paypalError', $context);
        self::assertArrayNotHasKey('debugId', $context);
    }

    public function testBrowserDiagnosticFieldsRejectMalformedValuesAndBoundIssues(): void
    {
        $config = Diagnostics::getSdkConfig();
        foreach ([['private@example.com', ['secret']], "NOT_AUTHORIZED\nsecret", str_repeat('A', 81)] as $invalid) {
            self::assertTrue(Diagnostics::logBrowserError($config['diagnosticsToken'], json_encode([
                'operation' => 'executeOrder',
                'sdkErrorCode' => $invalid,
                'paypalError' => $invalid,
                'paypalIssues' => [$invalid],
                'debugId' => $invalid,
                'httpStatus' => '403'
            ], JSON_THROW_ON_ERROR)));
        }

        foreach ($this->Handler->getRecords() as $record) {
            foreach (['sdkErrorCode', 'paypalError', 'paypalIssues', 'debugId', 'httpStatus', 'diagnosticHint'] as $key) {
                self::assertArrayNotHasKey($key, $record['context']);
            }
        }

        self::assertTrue(Diagnostics::logBrowserError($config['diagnosticsToken'], json_encode([
            'operation' => 'executeOrder',
            'paypalIssues' => array_map(static fn ($i) => 'ISSUE_' . $i, range(1, 30))
        ], JSON_THROW_ON_ERROR)));
        $records = $this->Handler->getRecords();
        self::assertCount(10, end($records)['context']['paypalIssues']);
    }

    public function testApiLogContainsStatusAndCodesButNoResponseBody(): void
    {
        $context = Diagnostics::responseContext(422, json_encode([
            'name' => 'UNPROCESSABLE_ENTITY',
            'message' => 'private@example.com',
            'details' => [['issue' => 'PAYEE_ACCOUNT_RESTRICTED', 'value' => 'secret-token']],
            'payer' => ['email_address' => 'private@example.com']
        ]), ['paypal-debug-id' => ['abcdef1234567'], 'Authorization' => 'Bearer secret-token']);
        Diagnostics::logApiFailure('OrdersController::captureOrder', new ResponseException('private error', $context));
        $record = $this->Handler->getRecords()[0];
        self::assertSame(422, $record['context']['httpStatus']);
        self::assertSame('UNPROCESSABLE_ENTITY', $record['context']['paypalError']);
        self::assertSame(['PAYEE_ACCOUNT_RESTRICTED'], $record['context']['paypalIssues']);
        self::assertSame('abcdef1234567', $record['context']['debugId']);
        self::assertStringNotContainsString('private', json_encode($record));
        self::assertStringNotContainsString('secret', json_encode($record));
    }

    public function testSdkExceptionResponseProducesDiagnosticLogInPaymentFailurePath(): void
    {
        $Response = $this->createMock(HttpResponse::class);
        $Response->method('getStatusCode')->willReturn(403);
        $Response->method('getRawBody')->willReturn('{"name":"NOT_AUTHORIZED","message":"private payer data"}');
        $Response->method('getHeaders')->willReturn(['Paypal-Debug-Id' => 'abcdef1234567']);
        $Exception = $this->createMock(ApiException::class);
        $Exception->method('getHttpResponse')->willReturn($Response);
        $Client = new PayPalServerClientDouble();
        $Client->exception = $Exception;
        $Payment = new PayPalServerApiPaymentDouble($Client);

        try {
            $Payment->payPalApiRequest(
                Payment::PAYPAL_REQUEST_TYPE_CREATE_ORDER,
                ['payer' => ['email_address' => 'private@example.com'], 'token' => 'secret-token'],
                [],
                true
            );
            self::fail('Failed API call must throw.');
        } catch (PayPalSystemException) {
            $record = $this->Handler->getRecords()[0];
            self::assertSame('OrdersController::createOrder', $record['context']['paypalOperation']);
            self::assertSame(403, $record['context']['httpStatus']);
            self::assertSame('NOT_AUTHORIZED', $record['context']['paypalError']);
            self::assertSame('abcdef1234567', $record['context']['debugId']);
            self::assertStringNotContainsString('private', json_encode($record));
            self::assertStringNotContainsString('secret', json_encode($record));
        }
    }

    public function testCronApiFailuresIdentifyEachOrderAndResetCronContext(): void
    {
        $Client = new PayPalServerClientDouble();
        $Client->exception = new ResponseException('private response secret-token', [
            'httpStatus' => 503,
            'paypalError' => 'SERVICE_UNAVAILABLE',
            'debugId' => 'abcdef1234567'
        ]);
        $orders = [];

        foreach ([41 => Constants::PAYMENT_STATUS_OPEN, 42 => Constants::PAYMENT_STATUS_PART] as $id => $status) {
            $Order = $this->createMock(AbstractOrder::class);
            $Order->method('getId')->willReturn($id);
            $Order->method('getUUID')->willReturn('11111111-2222-4333-8444-5555555555' . $id);
            $Order->method('getAttribute')->with('paid_status')->willReturn($status);
            $Order->method('getPaymentDataEntry')->willReturnMap([
                [Payment::ATTR_PAYPAL_ORDER_ID, 'PAYPAL-ORDER-' . $id],
                [Payment::ATTR_PAYPAL_ORDER_DOES_NOT_EXIST, false]
            ]);
            $Order->expects(self::never())->method('update');
            $orders[$id] = $Order;
        }

        $Payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getPendingCapturePaymentTypeIds', 'getPendingCaptureOrderRows',
                'getPendingCaptureOrder', 'getPayPalServerClient', 'saveOrder'
            ])
            ->getMock();
        $Payment->method('getPendingCapturePaymentTypeIds')->willReturn([1]);
        $Payment->method('getPendingCaptureOrderRows')->willReturn([['id' => 41], ['id' => 42]]);
        $Payment->method('getPendingCaptureOrder')->willReturnCallback(static fn ($id) => $orders[$id]);
        $Payment->method('getPayPalServerClient')->willReturn($Client);
        $Payment->expects(self::never())->method('saveOrder');

        $Payment->checkPendingCaptures();

        $records = $this->Handler->getRecords();
        self::assertCount(2, $records);

        foreach ($records as $index => $record) {
            $context = $record['context'];
            $id = 41 + $index;
            self::assertSame($id, $context['orderId']);
            self::assertSame($orders[$id]->getUUID(), $context['orderUuid']);
            self::assertSame('PAYPAL-ORDER-' . $id, $context['paypalOrderId']);
            self::assertSame($index === 0 ? Constants::PAYMENT_STATUS_OPEN : Constants::PAYMENT_STATUS_PART, $context['paidStatus']);
            self::assertSame($index === 0 ? 'open' : 'partially_paid', $context['paidStatusName']);
            self::assertSame('cron', $context['source']);
            self::assertSame('checkPendingCaptures', $context['cronJob']);
            self::assertSame('OrdersController::getOrder', $context['paypalOperation']);
            self::assertSame(503, $context['httpStatus']);
            self::assertSame('SERVICE_UNAVAILABLE', $context['paypalError']);
            self::assertSame('abcdef1234567', $context['debugId']);
        }

        try {
            $Payment->payPalApiRequest(Payment::PAYPAL_REQUEST_TYPE_GET_ORDER, [], $orders[41], true);
            self::fail('Failed API call must throw.');
        } catch (PayPalSystemException) {
            $records = $this->Handler->getRecords();
            self::assertCount(3, $records);
            self::assertArrayNotHasKey('cronJob', $records[2]['context']);
            self::assertArrayNotHasKey('source', $records[2]['context']);
            self::assertSame(41, $records[2]['context']['orderId']);
        }

        self::assertStringNotContainsString('private', json_encode($records));
        self::assertStringNotContainsString('secret', json_encode($records));
    }

    public function testOrderDiagnosticsRejectMalformedIdsAndPaymentStatus(): void
    {
        $Order = $this->createMock(AbstractOrder::class);
        $Order->method('getId')->willReturn(41);
        $Order->method('getUUID')->willReturn('private@example.com');
        $Order->method('getPaymentDataEntry')->willReturn("PAYPAL-ID\nsecret-token");
        $Order->method('getAttribute')->willReturn('private payment data');

        Diagnostics::logApiFailure('OrdersController::getOrder', new \RuntimeException('secret'), $Order);

        $record = $this->Handler->getRecords()[0];
        self::assertSame(41, $record['context']['orderId']);

        foreach (['orderUuid', 'paypalOrderId', 'paidStatus', 'paidStatusName'] as $key) {
            self::assertArrayNotHasKey($key, $record['context']);
        }

        self::assertStringNotContainsString('private', json_encode($record));
        self::assertStringNotContainsString('secret', json_encode($record));
    }
}
