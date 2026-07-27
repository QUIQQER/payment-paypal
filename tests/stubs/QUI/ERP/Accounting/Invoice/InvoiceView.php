<?php

namespace QUI\ERP\Accounting\Invoice;

if (!class_exists(InvoiceView::class, false)) {
    class InvoiceView
    {
        public function getCustomer(): ?\QUI\ERP\User
        {
            return null;
        }
    }
}
