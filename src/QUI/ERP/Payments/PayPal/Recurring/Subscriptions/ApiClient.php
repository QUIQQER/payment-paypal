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
     * @param array $body
     * @return array
     * @throws PayPalException
     */
    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    /**
     * @param string $path
     * @param array $body
     * @return array
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
     * @param array $headers
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
     * @param array|null $body
     * @return array
     * @throws PayPalException
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $Curl = curl_init($this->getBaseUrl() . $path);

        if ($Curl === false) {
            throw new PayPalException('Could not initialize PayPal request.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->getAccessToken()
        ];

        $payload = null;

        if ($body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt_array($Curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30
        ]);

        $result = curl_exec($Curl);
        $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
        curl_close($Curl);

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

        $Curl = curl_init($this->getBaseUrl() . '/v1/oauth2/token');

        if ($Curl === false) {
            throw new PayPalException('Could not initialize PayPal OAuth request.');
        }

        curl_setopt_array($Curl, [
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
        ]);

        $result = curl_exec($Curl);
        $status = curl_getinfo($Curl, CURLINFO_RESPONSE_CODE);
        curl_close($Curl);

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
