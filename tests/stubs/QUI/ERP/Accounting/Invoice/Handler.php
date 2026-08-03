<?php

namespace QUI\ERP\Accounting\Invoice;

if (!class_exists(Handler::class, false)) {
    class Handler
    {
        public static function getInstance(): self
        {
            return new self();
        }

        public function get(int | string $id): Invoice | InvoiceTemporary
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getInvoice(int | string $id): Invoice
        {
            throw new \LogicException('PHPStan stub');
        }

        public function getTemporaryInvoice(int | string $id): InvoiceTemporary
        {
            throw new \LogicException('PHPStan stub');
        }

        /**
         * @param array<string, mixed> $params
         * @return list<array<string, mixed>>
         */
        public function search(array $params = []): array
        {
            return [];
        }

        /**
         * @param array<string, mixed> $params
         * @return list<array<string, mixed>>
         */
        public function searchTemporaryInvoices(array $params = []): array
        {
            return [];
        }
    }
}
