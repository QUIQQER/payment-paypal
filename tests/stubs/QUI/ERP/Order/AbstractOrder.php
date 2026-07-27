<?php

namespace QUI\ERP\Order;

if (!class_exists(AbstractOrder::class, false)) {
    abstract class AbstractOrder implements OrderInterface
    {
        /** @var array<string, mixed> */
        protected array $attributes = [];

        protected ?\QUI\ERP\Accounting\ArticleList $Articles = null;

        protected ?\QUI\ERP\Accounting\Payments\Types\Payment $stubPayment = null;

        /** @var array<string, mixed> */
        protected array $paymentData = [];

        public function addHistory(string $message): void
        {
        }

        public function getArticles(): \QUI\ERP\Accounting\ArticleList
        {
            if ($this->Articles === null) {
                throw new \LogicException('No article list configured for test order.');
            }

            return $this->Articles;
        }

        public function getAttribute(string $key): mixed
        {
            return $this->attributes[$key] ?? null;
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
            if (
                property_exists($this, 'DeliveryAddress')
                && $this->DeliveryAddress instanceof \QUI\ERP\Address
            ) {
                return $this->DeliveryAddress;
            }

            throw new \LogicException('No delivery address configured for test order.');
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
            if (
                property_exists($this, 'InvoiceAddress')
                && $this->InvoiceAddress instanceof \QUI\ERP\Address
            ) {
                return $this->InvoiceAddress;
            }

            throw new \LogicException('No invoice address configured for test order.');
        }

        public function getPayment(): ?\QUI\ERP\Accounting\Payments\Types\Payment
        {
            return $this->stubPayment;
        }

        public function getPaymentDataEntry(string $key): mixed
        {
            return $this->paymentData[$key] ?? null;
        }

        public function getPriceCalculation(): \QUI\ERP\Accounting\Calculations
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getPrefixedId(): string
        {
            return '';
        }

        public function getHash(): string
        {
            return $this->getUUID();
        }

        public function getUUID(): string
        {
            return '';
        }

        public function isSuccessful(): int
        {
            return 0;
        }

        public function recalculate(mixed $Basket = null): void
        {
        }

        public function refresh(): void
        {
        }

        public function setDeliveryAddress(array | \QUI\ERP\Address $address): void
        {
        }

        public function setInvoiceAddress(array | \QUI\Users\Address $address): void
        {
        }

        public function setPayment(int | string $paymentId): void
        {
        }

        public function setPaymentData(string $key, mixed $value): void
        {
            $this->paymentData[$key] = $value;
        }

        public function setShipping(\QUI\ERP\Shipping\Api\ShippingInterface $Shipping): void
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
