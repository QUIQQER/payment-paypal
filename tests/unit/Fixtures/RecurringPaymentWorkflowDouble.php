<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Payment;

final class RecurringPaymentWorkflowDouble extends Payment
{
    public null|bool|array $apiResponse = [];
    public ?PayPalException $apiException = null;
    public array $apiCalls = [];

    public function payPalApiRequest(
        string $request,
        array $body,
        Transaction|AbstractOrder|array $TransactionObj,
        bool $throwSystemException = false
    ): null|bool|array {
        $this->apiCalls[] = [
            'request' => $request,
            'body' => $body,
            'transaction' => $TransactionObj,
            'throwSystemException' => $throwSystemException
        ];

        if ($this->apiException instanceof PayPalException) {
            throw $this->apiException;
        }

        return $this->apiResponse;
    }
}
