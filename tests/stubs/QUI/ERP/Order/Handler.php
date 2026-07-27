<?php

namespace QUI\ERP\Order;

if (!class_exists(Handler::class)) {
    class Handler
    {
        public static function getInstance(): self
        {
            return new self();
        }

        public function get(int | string $orderId): Order | OrderInProcess
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getOrderByHash(string $hash): Order | OrderInProcess
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getOrderById(int | string $id): Order | OrderInProcess
        {
            throw new \LogicException('PHPStan stub');
        }
    }
}
