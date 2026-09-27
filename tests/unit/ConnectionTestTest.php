<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use InvalidArgumentException;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;
use QUI\Log\Logger;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\ConnectionTestDouble;

final class ConnectionTestTest extends TestCase
{
    private TestHandler $Handler;

    protected function setUp(): void
    {
        $this->Handler = new TestHandler();
        Logger::getLogger()->pushHandler($this->Handler);
    }

    protected function tearDown(): void
    {
        Logger::getLogger()->popHandler();
    }

    public function testSuccessfulTestOnlyCallsAuthenticationAndEligibilityAndDoesNotLogSecrets(): void
    {
        $Test = new ConnectionTestDouble('private-client', 'private-secret', false);
        $Test->responses = [
            $this->response(200, ['access_token' => 'private-token']),
            $this->response(200, ['eligible_methods' => ['paypal' => ['can_be_vaulted' => true]]])
        ];
        $result = $Test->run('EUR', 'DE');
        self::assertSame('production', $result['environment']);
        self::assertTrue($result['steps'][0]['ok']);
        self::assertTrue($result['steps'][1]['ok']);
        self::assertTrue($result['steps'][1]['paypalEligible']);
        self::assertSame(
            ['/v1/oauth2/token', '/v2/payments/find-eligible-methods'],
            array_column($Test->requests, 'path')
        );
        self::assertSame('private-client:private-secret', $Test->requests[0]['credentials']);
        self::assertSame('grant_type=client_credentials', $Test->requests[0]['body']);
        self::assertNull($Test->requests[1]['credentials']);
        self::assertContains('Authorization: Bearer private-token', $Test->requests[1]['headers']);
        $payload = json_decode($Test->requests[1]['body'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['country_code' => 'DE'], $payload['customer']);
        self::assertSame(['currency_code' => 'EUR', 'value' => '1.00'], $payload['purchase_units'][0]['amount']);
        self::assertSame('ONE_TIME_PAYMENT', $payload['preferences']['payment_flow']);
        self::assertStringNotContainsString('private', json_encode($result));
        self::assertCount(0, $this->Handler->getRecords());
    }

    public function testAuthorizationFailureIsDisplayedAndLoggedWithActualApiDebugId(): void
    {
        $Test = new ConnectionTestDouble('private-client', 'private-secret', false);
        $Test->responses = [
            $this->response(200, ['access_token' => 'private-token']),
            $this->response(403, [
                'name' => 'NOT_AUTHORIZED',
                'debug_id' => '730b69995797f',
                'message' => 'private@example.com private-token',
                'details' => [['issue' => 'NOT_AUTHORIZED', 'description' => 'private response']]
            ])
        ];
        $result = $Test->run();
        $step = $result['steps'][1];
        self::assertFalse($step['ok']);
        self::assertSame(403, $step['httpStatus']);
        self::assertSame('NOT_AUTHORIZED', $step['paypalError']);
        self::assertSame(['NOT_AUTHORIZED'], $step['paypalIssues']);
        self::assertSame('730b69995797f', $step['debugId']);
        $records = $this->Handler->getRecords();
        self::assertCount(1, $records);
        $context = $records[0]['context'];
        self::assertSame('admin_connection_test', $context['source']);
        self::assertSame($result['testId'], $context['testId']);
        self::assertSame('findEligibleMethods', $context['paypalOperation']);
        self::assertSame('production', $context['testedEnvironment']);
        self::assertSame(403, $context['httpStatus']);
        self::assertSame('730b69995797f', $context['debugId']);
        self::assertStringNotContainsString('private', json_encode([$result, $records]));
    }

    public function testAuthenticationFailureStopsBeforeEligibilityAndReadsDebugHeader(): void
    {
        $Test = new ConnectionTestDouble('private-client', 'private-secret', true);
        $response = $this->response(401, ['error' => 'invalid_client', 'error_description' => 'private secret']);
        $response['headers'] = ['paypal-debug-id' => 'abcdef1234567'];
        $Test->responses = [$response];
        $result = $Test->run();
        self::assertSame('sandbox', $result['environment']);
        self::assertCount(1, $Test->requests);
        self::assertSame('invalid_client', $result['steps'][0]['paypalError']);
        self::assertSame('abcdef1234567', $result['steps'][0]['debugId']);
        self::assertStringNotContainsString('private', json_encode([$result, $this->Handler->getRecords()]));
    }

    public function testMissingCredentialsDoNotMakeRequests(): void
    {
        $Test = new ConnectionTestDouble('client', '', false);
        $result = $Test->run();
        self::assertSame('missing_credentials', $result['steps'][0]['reason']);
        self::assertSame([], $Test->requests);
    }

    public function testInvalidInputDoesNotMakeRequests(): void
    {
        $Test = new ConnectionTestDouble('client', 'secret', false);
        $this->expectException(InvalidArgumentException::class);
        $Test->run("EUR\n", 'DE');
    }

    public function testUnavailablePaymentMethodIsDifferentFromApiFailure(): void
    {
        $Test = new ConnectionTestDouble('client', 'secret', false);
        $Test->responses = [
            $this->response(200, ['access_token' => 'private-token']),
            $this->response(200, ['eligible_methods' => []])
        ];
        $result = $Test->run();
        self::assertTrue($result['steps'][1]['ok']);
        self::assertFalse($result['steps'][1]['paypalEligible']);
        self::assertCount(0, $this->Handler->getRecords());
    }

    public function testMalformedSuccessResponseDoesNotPassTheTest(): void
    {
        $Test = new ConnectionTestDouble('client', 'secret', false);
        $Test->responses = [$this->response(200, ['access_token' => ['private-invalid']])];
        $result = $Test->run();
        self::assertFalse($result['steps'][0]['ok']);
        self::assertSame('invalid_token_response', $result['steps'][0]['reason']);
        self::assertCount(1, $Test->requests);

        $Test->responses = [
            $this->response(200, ['access_token' => 'private-token']),
            $this->response(200, ['private-invalid' => true])
        ];
        $result = $Test->run();
        self::assertFalse($result['steps'][1]['ok']);
        self::assertSame('invalid_eligibility_response', $result['steps'][1]['reason']);
    }

    public function testTransportFailureIsReportedWithoutRawCurlError(): void
    {
        $Test = new ConnectionTestDouble('client', 'secret', false);
        $Test->responses = [['status' => 0, 'body' => false, 'headers' => [], 'transportError' => 28]];
        $result = $Test->run();
        self::assertSame('transport_error', $result['steps'][0]['reason']);
        self::assertSame(28, $result['steps'][0]['transportErrorCode']);
        self::assertArrayNotHasKey('httpStatus', $result['steps'][0]);
    }

    private function response(int $status, array $body): array
    {
        return ['status' => $status, 'body' => json_encode($body, JSON_THROW_ON_ERROR),
            'headers' => [], 'transportError' => 0];
    }
}
