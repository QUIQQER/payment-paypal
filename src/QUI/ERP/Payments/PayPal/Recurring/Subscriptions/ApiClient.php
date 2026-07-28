<?php

namespace QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

use QUI;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Provider;

use function http_build_query;
use function is_array;
use function json_decode;
use function json_encode;

use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_USERPWD;

/**
 * Minimal REST client for modern PayPal subscriptions endpoints.
 */
class ApiClient
{
    protected ?string $accessToken = null;

    /**
     * @param string $path
     * @param array<mixed> $body
     * @param string|null $requestId
     * @return array<mixed>
     * @throws PayPalException
     */
    public function post(string $path, array $body = [], ?string $requestId = null): array
    {
        return $this->request('POST', $path, $body, $requestId);
    }

    /**
     * @param string $path
     * @param array<mixed> $body
     * @return array<mixed>
     * @throws PayPalException
     */
    public function get(string $path, array $body = []): array
    {
        if (!empty($body)) {
            $path .= '?' . http_build_query($body);
        }

        return $this->request('GET', $path);
    }

    /**
     * @param array<string, string> $headers
     * @param string $rawBody
     * @param string $webhookId
     * @return bool
     * @throws PayPalException
     */
    public function verifyWebhookSignature(array $headers, string $rawBody, string $webhookId): bool
    {
        $event = json_decode($rawBody, true);

        if (!is_array($event)) {
            return false;
        }

        $response = $this->post('/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $headers['paypal-auth-algo'] ?? '',
            'cert_url' => $headers['paypal-cert-url'] ?? '',
            'transmission_id' => $headers['paypal-transmission-id'] ?? '',
            'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
            'transmission_time' => $headers['paypal-transmission-time'] ?? '',
            'webhook_id' => $webhookId,
            'webhook_event' => $event
        ]);

        return ($response['verification_status'] ?? '') === 'SUCCESS';
    }

    /**
     * @param string $method
     * @param string $path
     * @param array<mixed>|null $body
     * @param string|null $requestId
     * @return array<mixed>
     * @throws PayPalException
     */
    protected function request(
        string $method,
        string $path,
        ?array $body = null,
        ?string $requestId = null
    ): array {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->getAccessToken()
        ];

        if ($requestId !== null && $requestId !== '') {
            $headers[] = 'PayPal-Request-Id: ' . $requestId;
        }

        $payload = null;

        if ($body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }

        $curlResponse = $this->executeCurlRequest(
            $this->getBaseUrl() . $path,
            [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30
            ],
            'Could not initialize PayPal request.'
        );
        $result = $curlResponse['body'];
        $status = $curlResponse['status'];

        if ($result === false) {
            throw new PayPalException('PayPal request failed.');
        }

        $response = json_decode($result, true);

        if (!is_array($response)) {
            $response = [];
        }

        if ($status < 200 || $status >= 300) {
            QUI\System\Log::write(
                'PayPal Subscriptions API request failed',
                QUI\System\Log::LEVEL_WARNING,
                [
                    'method' => $method,
                    'path' => $path,
                    'status' => $status,
                    'response' => $response
                ],
                'paypal_api'
            );

            throw new PayPalException(
                !empty($response['message'])
                    ? $response['message']
                    : QUI::getLocale()->get(
                        'quiqqer/payment-paypal',
                        'exception.Recurring.order.error'
                    ),
                $status
            );
        }

        return $response;
    }

    /**
     * @return string
     * @throws PayPalException
     */
    protected function getAccessToken(): string
    {
        if (!empty($this->accessToken)) {
            return $this->accessToken;
        }

        if (Provider::getApiSetting('sandbox')) {
            $clientId = Provider::getApiSetting('sandbox_client_id');
            $clientSecret = Provider::getApiSetting('sandbox_client_secret');
        } else {
            $clientId = Provider::getApiSetting('client_id');
            $clientSecret = Provider::getApiSetting('client_secret');
        }

        $curlResponse = $this->executeCurlRequest(
            $this->getBaseUrl() . '/v1/oauth2/token',
            [
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Accept-Language: en_US',
                    'Content-Type: application/x-www-form-urlencoded'
                ],
                CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_USERPWD => $clientId . ':' . $clientSecret
            ],
            'Could not initialize PayPal OAuth request.'
        );
        $result = $curlResponse['body'];
        $status = $curlResponse['status'];

        if ($result === false) {
            throw new PayPalException('Could not fetch PayPal access token.');
        }

        $response = json_decode($result, true);

        if ($status < 200 || $status >= 300 || empty($response['access_token'])) {
            throw new PayPalException(
                !empty($response['error_description'])
                    ? $response['error_description']
                    : 'Could not fetch PayPal access token.',
                $status
            );
        }

        $this->accessToken = $response['access_token'];

        return $this->accessToken;
    }

    /**
     * @param array<int, mixed> $options
     * @return array{body: string|false, status: int}
     * @throws PayPalException
     */
    protected function executeCurlRequest(
        string $url,
        array $options,
        string $initializationError
    ): array {
        $Curl = curl_init($url);

        if ($Curl === false) {
            throw new PayPalException($initializationError);
        }

        curl_setopt_array($Curl, $options);

        $curlResult = curl_exec($Curl);
        $result = $curlResult === false ? false : (string)$curlResult;
        $status = (int)curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
        curl_close($Curl);

        return [
            'body' => $result,
            'status' => $status
        ];
    }

    /**
     * @return string
     */
    protected function getBaseUrl(): string
    {
        if (Provider::getApiSetting('sandbox')) {
            return 'https://api-m.sandbox.paypal.com';
        }

        return 'https://api-m.paypal.com';
    }
}
