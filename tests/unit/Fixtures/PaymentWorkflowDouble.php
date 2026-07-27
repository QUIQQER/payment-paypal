<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;

final class PaymentWorkflowDouble extends Payment
{
    public array $payPalData = [];
    public null|bool|array $apiResponse = [];
    public ?PayPalException $apiException = null;
    public array $apiCalls = [];
    public int $saveCount = 0;

    protected function getPayPalDataForOrder(AbstractOrder $Order): array
    {
        return $this->payPalData;
    }

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

        if ($this->apiException !== null) {
            throw $this->apiException;
        }

        return $this->apiResponse;
    }

    public function fetchPayPalOrderDetails(AbstractOrder $Order): bool|array
    {
        return $this->getPayPalOrderDetails($Order);
    }

    public function voidOrder(AbstractOrder $Order): void
    {
        $this->voidPayPalOrder($Order);
    }

    protected function saveOrder(AbstractOrder $Order): void
    {
        $this->saveCount++;
    }
}
