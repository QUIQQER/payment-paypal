<?php

namespace QUI\ERP\Accounting\Invoice;

if (!class_exists(InvoiceTemporary::class, false)) {
    class InvoiceTemporary
    {
        public function addHistory(string $message): void
        {
        }

        public function addTransaction(
            \QUI\ERP\Accounting\Payments\Transactions\Transaction $Transaction
        ): void {
        }

        public function calculatePayments(): void
        {
        }

        public function getAttribute(string $key): mixed
        {
            return null;
        }

        public function getCurrency(): \QUI\ERP\Currency\Currency
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getCustomer(): ?\QUI\ERP\User
        {
            return null;
        }

        public function getGlobalProcessId(): string
        {
            return '';
        }

        public function getId(): int
        {
            return 0;
        }

        public function getPaymentData(string $key): mixed
        {
            return null;
        }

        public function getUUID(): string
        {
            return '';
        }
    }
}
