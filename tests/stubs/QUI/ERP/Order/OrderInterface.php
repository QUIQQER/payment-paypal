<?php

namespace QUI\ERP\Order;

if (!interface_exists(OrderInterface::class, false)) {
    interface OrderInterface
    {
        public function getArticles(): \QUI\ERP\Accounting\ArticleList;

        public function getCurrency(): \QUI\ERP\Currency\Currency;

        public function getCustomer(): \QUI\ERP\User;

        public function getHash(): string;

        public function getPayment(): ?\QUI\ERP\Accounting\Payments\Types\Payment;

        public function getUUID(): string;

        public function isSuccessful(): int;
    }
}
