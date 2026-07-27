<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Payment;
use Throwable;

final class PendingCapturePaymentDouble extends Payment
{
    public array $paymentTypeIds = [1];
    public array $rows = [];
    public array $orders = [];
    public null|bool|array $apiResponse = [];
    public ?Throwable $apiException = null;
    public ?Transaction $Transaction = null;
    public array $purchase = [];
    public int $saveCount = 0;

    protected function getPendingCapturePaymentTypeIds(): array
    {
        return $this->paymentTypeIds;
    }

    protected function getPendingCaptureOrderRows(array $paymentTypeIds): array
    {
        return $this->rows;
    }

    protected function getPendingCaptureOrder(int|string $orderId): AbstractOrder
    {
        return $this->orders[$orderId];
    }

    public function payPalApiRequest(
        string $request,
        array $body,
        Transaction|AbstractOrder|array $TransactionObj,
        bool $throwSystemException = false
    ): null|bool|array {
        if ($this->apiException instanceof Throwable) {
            throw $this->apiException;
        }

        return $this->apiResponse;
    }

    protected function purchasePendingCapture(
        float $amount,
        string $currencyCode,
        AbstractOrder $Order
    ): Transaction {
        $this->purchase = [
            'amount' => $amount,
            'currencyCode' => $currencyCode,
            'order' => $Order
        ];

        return $this->Transaction;
    }

    protected function saveOrder(AbstractOrder $Order): void
    {
        $this->saveCount++;
    }
}
