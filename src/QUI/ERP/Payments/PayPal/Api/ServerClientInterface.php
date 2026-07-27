<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\Api;

interface ServerClientInterface
{
    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     */
    public function createOrder(array $body): ?array;

    /**
     * @return array<mixed>|null
     */
    public function getOrder(string $orderId): ?array;

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     */
    public function patchOrder(string $orderId, array $body): ?array;

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     */
    public function captureOrder(string $orderId, array $body): ?array;

    /**
     * @param array<mixed> $body
     * @return array<mixed>|null
     */
    public function refundCapturedPayment(string $captureId, array $body): ?array;
}
