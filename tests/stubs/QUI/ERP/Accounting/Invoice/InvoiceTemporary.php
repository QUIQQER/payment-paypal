<?php

namespace QUI\ERP\Accounting\Invoice;

if (!class_exists(InvoiceTemporary::class, false)) {
    class InvoiceTemporary
    {
        public function getCustomer(): ?\QUI\ERP\User
        {
            return null;
        }
    }
}
