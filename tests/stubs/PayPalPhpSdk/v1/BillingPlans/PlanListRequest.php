<?php

namespace QUI\ERP\Payments\PayPal\PhpSdk\v1\BillingPlans;

use QUI\ERP\Payments\PayPal\PhpSdk\Support\Request;

class PlanListRequest extends Request
{
    public function page(mixed $page): static
    {
        $this->parameters['page'] = $page;

        return $this;
    }

    public function pageSize(mixed $pageSize): static
    {
        $this->parameters['pageSize'] = $pageSize;

        return $this;
    }

    public function status(mixed $status): static
    {
        $this->parameters['status'] = $status;

        return $this;
    }

    public function totalRequired(mixed $totalRequired): static
    {
        $this->parameters['totalRequired'] = $totalRequired;

        return $this;
    }
}
