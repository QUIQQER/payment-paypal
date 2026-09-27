<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\Api;

use JsonException;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use QUI\ERP\Payments\PayPal\Diagnostics;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class ServerClient implements ServerClientInterface
{
    private PaypalServerSdkClient $Client;

    public function __construct(
        string $clientId,
        string $clientSecret,
        bool $sandbox,
        ?PaypalServerSdkClient $Client = null
    ) {
        if ($Client !== null) {
            $this->Client = $Client;
            return;
        }

        $environment = $sandbox ? Environment::SANDBOX : Environment::PRODUCTION;

        $this->Client = PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init($clientId, $clientSecret)
            )
            ->environment($environment)
            ->build();
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     * @throws JsonException
     */
    public function createOrder(array $body): ?array
    {
        return $this->normalizeResponse(
            $this->Client->getOrdersController()->createOrder([
                'body' => $body,
                'prefer' => 'return=representation'
            ])
        );
    }

    /**
     * @return array<mixed>|null
     * @throws JsonException
     */
    public function getOrder(string $orderId): ?array
    {
        return $this->normalizeResponse(
            $this->Client->getOrdersController()->getOrder([
                'id' => $orderId
            ])
        );
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     */
    public function patchOrder(string $orderId, array $body): ?array
    {
        $Response = $this->Client->getOrdersController()->patchOrder([
            'id' => $orderId,
            'body' => $body
        ]);

        $this->assertSuccessfulResponse($Response);

        // PayPal returns 204 No Content for successful order updates.
        return null;
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     * @throws JsonException
     */
    public function captureOrder(string $orderId, array $body): ?array
    {
        $options = [
            'id' => $orderId,
            'prefer' => 'return=representation'
        ];

        if (!empty($body)) {
            $options['body'] = $body;
        }

        return $this->normalizeResponse(
            $this->Client->getOrdersController()->captureOrder($options)
        );
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     * @throws JsonException
     */
    public function refundCapturedPayment(string $captureId, array $body): ?array
    {
        return $this->normalizeResponse(
            $this->Client->getPaymentsController()->refundCapturedPayment([
                'captureId' => $captureId,
                'body' => $body,
                'prefer' => 'return=representation'
            ])
        );
    }

    /**
     * @return array<mixed>|null
     * @throws JsonException
     */
    private function normalizeResponse(ApiResponse $Response): ?array
    {
        $this->assertSuccessfulResponse($Response);
        $result = $Response->getResult();

        if ($result === null) {
            return null;
        }

        try {
            $normalizedResult = json_decode(
                json_encode($result, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            $normalizedResult = null;
        }

        if (!is_array($normalizedResult)) {
            throw new ResponseException(
                'PayPal API response could not be normalized to an array.',
                Diagnostics::responseContext($Response->getStatusCode(), $Response->getBody(), $Response->getHeaders())
            );
        }

        return $normalizedResult;
    }

    private function assertSuccessfulResponse(ApiResponse $Response): void
    {
        $status = $Response->getStatusCode();

        if ($status === null || $status < 200 || $status >= 300) {
            throw new ResponseException(
                'PayPal API returned an unsuccessful HTTP response.',
                Diagnostics::responseContext($status, $Response->getBody(), $Response->getHeaders())
            );
        }
    }
}
