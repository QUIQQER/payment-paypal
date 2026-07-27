<?php

namespace QUI\ERP\Order;

if (!class_exists(AbstractOrder::class)) {
    abstract class AbstractOrder implements OrderInterface
    {
        public function addHistory(string $message): void
        {
        }

        public function getArticles(): \QUI\ERP\Accounting\ArticleList
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getAttribute(string $key): mixed
        {
            return null;
        }

        public function getCurrency(): \QUI\ERP\Currency\Currency
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getCustomer(): \QUI\ERP\User
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getDeliveryAddress(): \QUI\ERP\Address
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getGlobalProcessId(): string
        {
            return '';
        }

        public function getId(): int
        {
            return 0;
        }

        public function getInvoiceAddress(): \QUI\ERP\Address
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getPayment(): ?\QUI\ERP\Accounting\Payments\Types\Payment
        {
            return null;
        }

        public function getPaymentDataEntry(string $key): mixed
        {
            return null;
        }

        public function getPriceCalculation(): \QUI\ERP\Accounting\Calculations
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getUUID(): string
        {
            return '';
        }

        public function setInvoiceAddress(array | \QUI\Users\Address $address): void
        {
        }

        public function setPaymentData(string $key, mixed $value): void
        {
        }

        public function setSuccessfulStatus(): void
        {
        }

        public function update(?\QUI\Interfaces\Users\User $PermissionUser = null): void
        {
        }
    }
}
