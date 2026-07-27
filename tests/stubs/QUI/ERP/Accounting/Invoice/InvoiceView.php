<?php

namespace QUI\ERP\Accounting\Invoice;

if (!class_exists(InvoiceView::class)) {
    class InvoiceView
    {
        public function getCustomer(): ?\QUI\ERP\User
        {
            return null;
        }
    }
}
