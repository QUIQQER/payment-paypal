<?php

namespace QUI\ERP\Order;

if (!class_exists(OrderProcess::class)) {
    class OrderProcess
    {
        /**
         * @param array<string, mixed> $attributes
         */
        public function __construct(array $attributes = [])
        {
        }

        public function getOrder(): ?AbstractOrder
        {
            return null;
        }

        public function getProject(): ?\QUI\Projects\Project
        {
            return null;
        }

        public function getStepUrl(string $step): string
        {
            return '';
        }
    }
}
