<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Api\ConnectionTest;
use RuntimeException;

final class ConnectionTestDouble extends ConnectionTest
{
    public array $responses = [];
    public array $requests = [];

    protected function request(string $path, array $headers, string $body, ?string $credentials = null): array
    {
        $this->requests[] = compact('path', 'headers', 'body', 'credentials');

        if ($this->responses === []) {
            throw new RuntimeException('Unexpected PayPal request.');
        }

        return array_shift($this->responses);
    }
}
