<?php

namespace QUI\ERP\Order\Basket;

if (!class_exists(Basket::class)) {
    class Basket
    {
        public function __construct(int $basketId = 0)
        {
        }

        public function count(): int
        {
            return 0;
        }

        public function getId(): int
        {
            return 0;
        }

        public function getOrder(): ?\QUI\ERP\Order\AbstractOrder
        {
            return null;
        }

        public function hasOrder(): bool
        {
            return false;
        }

        public function toOrder(\QUI\ERP\Order\AbstractOrder $Order): void
        {
        }

        public function updateOrder(): void
        {
        }
    }
}
