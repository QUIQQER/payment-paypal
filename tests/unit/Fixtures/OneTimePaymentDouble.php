<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use QUI\ERP\Payments\PayPal\PayPalException;

final class OneTimePaymentDouble extends Payment
{
    public ?string $requestType = null;
    public array $requestBody = [];
    public Transaction|AbstractOrder|array|null $requestTransaction = null;
    public bool $saved = false;
    public bool $updated = false;
    public ?PayPalException $apiException = null;

    protected function getPayPalDataForOrder(AbstractOrder $Order): array
    {
        return ['intent' => 'CAPTURE'];
    }

    public function payPalApiRequest(
        string $request,
        array $body,
        Transaction|AbstractOrder|array $TransactionObj,
        bool $throwSystemException = false
    ): null|bool|array {
        $this->requestType = $request;
        $this->requestBody = $body;
        $this->requestTransaction = $TransactionObj;

        if ($this->apiException !== null) {
            throw $this->apiException;
        }

        return ['id' => 'PAYPAL-ORDER-ID'];
    }

    public function updatePayPalOrder(AbstractOrder $Order): void
    {
        $this->updated = true;
    }

    protected function saveOrder(AbstractOrder $Order): void
    {
        $this->saved = true;
    }
}
