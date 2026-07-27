<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Recurring\Subscriptions\ApiClient;
use RuntimeException;

final class SubscriptionsApiClientDouble extends ApiClient
{
    public array $requests = [];
    public array $responses = [];

    public function setAccessToken(?string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    public function fetchAccessToken(): string
    {
        return $this->getAccessToken();
    }

    public function fetchBaseUrl(): string
    {
        return $this->getBaseUrl();
    }

    protected function executeCurlRequest(
        string $url,
        array $options,
        string $initializationError
    ): array {
        $this->requests[] = [
            'url' => $url,
            'options' => $options,
            'initializationError' => $initializationError
        ];

        if ($this->responses === []) {
            throw new RuntimeException('No fake cURL response configured.');
        }

        return array_shift($this->responses);
    }
}
