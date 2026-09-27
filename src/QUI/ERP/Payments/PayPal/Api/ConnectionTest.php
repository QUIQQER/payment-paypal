<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\Api;

use InvalidArgumentException;
use QUI\ERP\Payments\PayPal\Diagnostics;

/** Diagnostic requests only; this class never creates or captures an order. */
class ConnectionTest
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly bool $sandbox
    ) {
    }

    /** @return array<string, mixed> */
    public function run(string $currency = 'EUR', string $country = 'DE'): array
    {
        if (preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1 || preg_match('/\A[A-Z]{2}\z/D', $country) !== 1) {
            throw new InvalidArgumentException('Invalid currency or country code.');
        }

        $testId = bin2hex(random_bytes(16));
        $result = [
            'testId' => $testId,
            'environment' => $this->sandbox ? 'sandbox' : 'production',
            'currency' => $currency,
            'country' => $country,
            'amount' => '1.00',
            'steps' => []
        ];

        if (trim($this->clientId) === '' || trim($this->clientSecret) === '') {
            $result['steps'][] = $this->step('configuration', false, ['reason' => 'missing_credentials'], $testId);
            return $result;
        }

        $response = $this->request(
            '/v1/oauth2/token',
            ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            'grant_type=client_credentials',
            $this->clientId . ':' . $this->clientSecret
        );
        $body = is_string($response['body']) ? json_decode($response['body'], true) : null;
        $token = is_array($body) ? ($body['access_token'] ?? null) : null;
        $ok = $response['status'] === 200 && is_string($token) && $token !== '';
        $context = $this->context($response);

        if (!$ok && $response['status'] === 200) {
            $context['reason'] = 'invalid_token_response';
        }

        $result['steps'][] = $this->step('authentication', $ok, $context, $testId);

        if (!$ok) {
            return $result;
        }

        $response = $this->request('/v2/payments/find-eligible-methods', [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token
        ], json_encode([
            'customer' => ['country_code' => $country],
            'purchase_units' => [['amount' => ['currency_code' => $currency, 'value' => '1.00']]],
            'preferences' => [
                'payment_flow' => 'ONE_TIME_PAYMENT',
                'payment_source_constraint' => ['constraint_type' => 'INCLUDE', 'payment_sources' => ['PAYPAL']]
            ]
        ], JSON_THROW_ON_ERROR));
        $body = is_string($response['body']) ? json_decode($response['body'], true) : null;
        $methods = is_array($body) ? ($body['eligible_methods'] ?? null) : null;
        $ok = $response['status'] === 200 && is_array($methods);
        $context = $this->context($response);

        if ($ok) {
            $context['paypalEligible'] = array_key_exists('paypal', $methods);
        } elseif ($response['status'] === 200) {
            $context['reason'] = 'invalid_eligibility_response';
        }

        $result['steps'][] = $this->step('findEligibleMethods', $ok, $context, $testId);
        return $result;
    }

    /**
     * @param array{status: int, body: string|false, headers: array<string, string>, transportError: int} $response
     * @return array<string, mixed>
     */
    private function context(array $response): array
    {
        $context = Diagnostics::responseContext(
            $response['status'] ?: null,
            $response['body'],
            $response['headers']
        );

        if ($response['transportError'] !== 0 || $response['body'] === false) {
            $context['reason'] = 'transport_error';
            $context['transportErrorCode'] = $response['transportError'];
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function step(string $operation, bool $ok, array $context, string $testId): array
    {
        if (!$ok) {
            Diagnostics::logConnectionTestFailure($operation, $context, $testId, $this->sandbox);
        }

        return ['operation' => $operation, 'ok' => $ok] + $context;
    }

    /**
     * @param list<string> $headers
     * @return array{status: int, body: string|false, headers: array<string, string>, transportError: int}
     */
    protected function request(string $path, array $headers, string $body, ?string $credentials = null): array
    {
        $baseUrl = $this->sandbox ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
        $Curl = curl_init($baseUrl . $path);

        if ($Curl === false) {
            return ['status' => 0, 'body' => false, 'headers' => [], 'transportError' => CURLE_FAILED_INIT];
        }

        $responseHeaders = [];
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($Handle, string $line) use (&$responseHeaders): int {
                $header = explode(':', $line, 2);

                if (count($header) === 2 && strtolower(trim($header[0])) === 'paypal-debug-id') {
                    $responseHeaders['paypal-debug-id'] = trim($header[1]);
                }

                return strlen($line);
            }
        ];

        if ($credentials !== null && $credentials !== '') {
            $options[CURLOPT_USERPWD] = $credentials;
        }

        curl_setopt_array($Curl, $options);
        $body = curl_exec($Curl);

        return [
            'status' => (int)curl_getinfo($Curl, CURLINFO_RESPONSE_CODE),
            'body' => is_string($body) ? $body : false,
            'headers' => $responseHeaders,
            'transportError' => curl_errno($Curl)
        ];
    }
}
