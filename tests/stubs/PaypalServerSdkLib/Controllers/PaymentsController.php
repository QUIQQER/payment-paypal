<?php

declare(strict_types=1);

namespace PaypalServerSdkLib\Controllers;

use PaypalServerSdkLib\Http\ApiResponse;

class PaymentsController
{
    public function refundCapturedPayment(array $options): ApiResponse
    {
        return new ApiResponse();
    }
}
