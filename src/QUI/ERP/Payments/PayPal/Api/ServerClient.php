<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\Api;

use JsonException;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;
use PaypalServerSdkLib\Http\ApiResponse;
use PaypalServerSdkLib\PaypalServerSdkClient;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use UnexpectedValueException;

use function is_array;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class ServerClient implements ServerClientInterface
{
    private PaypalServerSdkClient $Client;

    public function __construct(string $clientId, string $clientSecret, bool $sandbox)
    {
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
     * @throws JsonException
     */
    public function patchOrder(string $orderId, array $body): ?array
    {
        return $this->normalizeResponse(
            $this->Client->getOrdersController()->patchOrder([
                'id' => $orderId,
                'body' => $body
            ])
        );
    }

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     * @throws JsonException
     */
    public function captureOrder(string $orderId, array $body): ?array
    {
        return $this->normalizeResponse(
            $this->Client->getOrdersController()->captureOrder([
                'id' => $orderId,
                'body' => $body,
                'prefer' => 'return=representation'
            ])
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
        $result = $Response->getResult();

        if ($result === null) {
            return null;
        }

        $normalizedResult = json_decode(
            json_encode($result, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($normalizedResult)) {
            throw new UnexpectedValueException('PayPal API response could not be normalized to an array.');
        }

        return $normalizedResult;
    }
}
