<?php

declare(strict_types=1);

namespace PaypalServerSdkLib;

use PaypalServerSdkLib\Controllers\OrdersController;
use PaypalServerSdkLib\Controllers\PaymentsController;

class PaypalServerSdkClient
{
    public function getOrdersController(): OrdersController
    {
        return new OrdersController();
    }

    public function getPaymentsController(): PaymentsController
    {
        return new PaymentsController();
    }
}
