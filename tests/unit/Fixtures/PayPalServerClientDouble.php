<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Api\ServerClientInterface;
use RuntimeException;

final class PayPalServerClientDouble implements ServerClientInterface
{
    public array $calls = [];
    public ?array $response = ['id' => 'PAYPAL-RESULT-ID'];
    public bool $fail = false;

    public function createOrder(array $body): ?array
    {
        return $this->execute('createOrder', [$body]);
    }

    public function getOrder(string $orderId): ?array
    {
        return $this->execute('getOrder', [$orderId]);
    }

    public function patchOrder(string $orderId, array $body): ?array
    {
        return $this->execute('patchOrder', [$orderId, $body]);
    }

    public function captureOrder(string $orderId, array $body): ?array
    {
        return $this->execute('captureOrder', [$orderId, $body]);
    }

    public function refundCapturedPayment(string $captureId, array $body): ?array
    {
        return $this->execute('refundCapturedPayment', [$captureId, $body]);
    }

    private function execute(string $operation, array $arguments): ?array
    {
        $this->calls[] = [
            'operation' => $operation,
            'arguments' => $arguments
        ];

        if ($this->fail) {
            throw new RuntimeException('PayPal unavailable');
        }

        return $this->response;
    }
}
