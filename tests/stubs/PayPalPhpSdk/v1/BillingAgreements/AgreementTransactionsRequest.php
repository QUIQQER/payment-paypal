<?php

namespace QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingAgreements;

use QUI\ERP\Payments\PayPal\PhpSdk\Support\Request;

class AgreementTransactionsRequest extends Request
{
    public function startDate(mixed $startDate): static
    {
        $this->parameters['startDate'] = $startDate;

        return $this;
    }

    public function endDate(mixed $endDate): static
    {
        $this->parameters['endDate'] = $endDate;

        return $this;
    }
}
