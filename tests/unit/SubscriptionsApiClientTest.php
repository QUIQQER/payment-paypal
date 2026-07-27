<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Settings;
use QUITests\ERP\Payments\PayPal\Unit\Fixtures\SubscriptionsApiClientDouble;

use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_USERPWD;

final class SubscriptionsApiClientTest extends TestCase
{
    private object $Config;
    private array $previous = [];

    protected function setUp(): void
    {
        $this->Config = Settings::getConfig();

        foreach (
            [
                'sandbox',
                'sandbox_client_id',
                'sandbox_client_secret',
                'client_id',
                'client_secret'
            ] as $key
        ) {
            $this->previous[$key] = $this->Config->get('api', $key);
        }

        $this->Config->setValue('api', 'sandbox', 1);
        $this->Config->setValue('api', 'sandbox_client_id', 'sandbox-client');
        $this->Config->setValue('api', 'sandbox_client_secret', 'sandbox-secret');
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                $this->Config->del('api', $key);
                continue;
            }

            $this->Config->setValue('api', $key, match ($value) {
                true => 1,
                false => 0,
                default => $value
            });
        }
    }

    public function testPostSendsJsonWithBearerToken(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => '{"id":"SUBSCRIPTION-1"}',
            'status' => 201
        ];

        self::assertSame(
            ['id' => 'SUBSCRIPTION-1'],
            $Client->post('/v1/billing/subscriptions', ['plan_id' => 'PLAN-1'])
        );

        $request = $Client->requests[0];
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v1/billing/subscriptions',
            $request['url']
        );
        self::assertSame('POST', $request['options'][CURLOPT_CUSTOMREQUEST]);
        self::assertContains(
            'Authorization: Bearer ACCESS-TOKEN',
            $request['options'][CURLOPT_HTTPHEADER]
        );
        self::assertContains(
            'Content-Type: application/json',
            $request['options'][CURLOPT_HTTPHEADER]
        );
        self::assertSame(
            '{"plan_id":"PLAN-1"}',
            $request['options'][CURLOPT_POSTFIELDS]
        );
    }

    public function testGetAppendsEncodedQueryParameters(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => '{"plans":[]}',
            'status' => 200
        ];

        self::assertSame(
            ['plans' => []],
            $Client->get('/v1/billing/plans', [
                'page' => 2,
                'status' => 'ACTIVE'
            ])
        );
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v1/billing/plans?page=2&status=ACTIVE',
            $Client->requests[0]['url']
        );
        self::assertSame('GET', $Client->requests[0]['options'][CURLOPT_CUSTOMREQUEST]);
        self::assertNull($Client->requests[0]['options'][CURLOPT_POSTFIELDS]);
    }

    public function testSuccessfulNonJsonResponseReturnsEmptyArray(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => 'no-json',
            'status' => 204
        ];

        self::assertSame([], $Client->get('/v1/resource'));
    }

    public function testApiErrorUsesPayPalMessageAndStatus(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => '{"message":"Invalid subscription"}',
            'status' => 422
        ];

        try {
            $Client->post('/v1/billing/subscriptions');
            self::fail('PayPalException was not thrown.');
        } catch (PayPalException $Exception) {
            self::assertSame('Invalid subscription', $Exception->getMessage());
            self::assertSame(422, $Exception->getCode());
        }
    }

    public function testTransportFailureThrowsPayPalException(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => false,
            'status' => 0
        ];

        $this->expectException(PayPalException::class);
        $this->expectExceptionMessage('PayPal request failed.');

        $Client->get('/v1/resource');
    }

    public function testInvalidWebhookBodyIsRejectedWithoutRequest(): void
    {
        $Client = new SubscriptionsApiClientDouble();

        self::assertFalse(
            $Client->verifyWebhookSignature([], 'invalid-json', 'WEBHOOK-1')
        );
        self::assertSame([], $Client->requests);
    }

    public function testWebhookSignaturePayloadIsVerified(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => '{"verification_status":"SUCCESS"}',
            'status' => 200
        ];
        $headers = [
            'paypal-auth-algo' => 'SHA256withRSA',
            'paypal-cert-url' => 'https://example.test/cert',
            'paypal-transmission-id' => 'TRANSMISSION-1',
            'paypal-transmission-sig' => 'signature',
            'paypal-transmission-time' => '2026-07-27T10:00:00Z'
        ];

        self::assertTrue(
            $Client->verifyWebhookSignature(
                $headers,
                '{"id":"EVENT-1"}',
                'WEBHOOK-1'
            )
        );

        $payload = json_decode(
            $Client->requests[0]['options'][CURLOPT_POSTFIELDS],
            true
        );
        self::assertSame([
            'auth_algo' => 'SHA256withRSA',
            'cert_url' => 'https://example.test/cert',
            'transmission_id' => 'TRANSMISSION-1',
            'transmission_sig' => 'signature',
            'transmission_time' => '2026-07-27T10:00:00Z',
            'webhook_id' => 'WEBHOOK-1',
            'webhook_event' => ['id' => 'EVENT-1']
        ], $payload);
    }

    public function testNonSuccessWebhookStatusIsRejected(): void
    {
        $Client = $this->createAuthenticatedClient();
        $Client->responses[] = [
            'body' => '{"verification_status":"FAILURE"}',
            'status' => 200
        ];

        self::assertFalse(
            $Client->verifyWebhookSignature([], '{}', 'WEBHOOK-1')
        );
    }

    public function testSandboxAccessTokenIsFetchedAndCached(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->responses[] = [
            'body' => '{"access_token":"SANDBOX-TOKEN"}',
            'status' => 200
        ];

        self::assertSame('SANDBOX-TOKEN', $Client->fetchAccessToken());
        self::assertSame('SANDBOX-TOKEN', $Client->fetchAccessToken());
        self::assertCount(1, $Client->requests);
        self::assertSame(
            'https://api-m.sandbox.paypal.com/v1/oauth2/token',
            $Client->requests[0]['url']
        );
        self::assertSame(
            'sandbox-client:sandbox-secret',
            $Client->requests[0]['options'][CURLOPT_USERPWD]
        );
    }

    public function testProductionAccessTokenUsesProductionCredentials(): void
    {
        $this->Config->setValue('api', 'sandbox', 0);
        $this->Config->setValue('api', 'client_id', 'production-client');
        $this->Config->setValue('api', 'client_secret', 'production-secret');

        $Client = new SubscriptionsApiClientDouble();
        $Client->responses[] = [
            'body' => '{"access_token":"PRODUCTION-TOKEN"}',
            'status' => 200
        ];

        self::assertSame('PRODUCTION-TOKEN', $Client->fetchAccessToken());
        self::assertSame(
            'https://api-m.paypal.com/v1/oauth2/token',
            $Client->requests[0]['url']
        );
        self::assertSame(
            'production-client:production-secret',
            $Client->requests[0]['options'][CURLOPT_USERPWD]
        );
    }

    public function testAccessTokenTransportFailureIsReported(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->responses[] = [
            'body' => false,
            'status' => 0
        ];

        $this->expectException(PayPalException::class);
        $this->expectExceptionMessage('Could not fetch PayPal access token.');

        $Client->fetchAccessToken();
    }

    public function testAccessTokenApiErrorUsesDescription(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->responses[] = [
            'body' => '{"error_description":"Invalid client credentials"}',
            'status' => 401
        ];

        try {
            $Client->fetchAccessToken();
            self::fail('PayPalException was not thrown.');
        } catch (PayPalException $Exception) {
            self::assertSame('Invalid client credentials', $Exception->getMessage());
            self::assertSame(401, $Exception->getCode());
        }
    }

    public function testAccessTokenWithoutTokenUsesFallbackMessage(): void
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->responses[] = [
            'body' => '{}',
            'status' => 200
        ];

        $this->expectException(PayPalException::class);
        $this->expectExceptionMessage('Could not fetch PayPal access token.');

        $Client->fetchAccessToken();
    }

    private function createAuthenticatedClient(): SubscriptionsApiClientDouble
    {
        $Client = new SubscriptionsApiClientDouble();
        $Client->setAccessToken('ACCESS-TOKEN');

        return $Client;
    }
}
