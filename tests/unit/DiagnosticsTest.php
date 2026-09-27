<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use QUI;
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
}
