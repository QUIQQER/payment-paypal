<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Accounting\ArticleList;
use QUI\ERP\Accounting\Calculations;
use QUI\ERP\Currency\Currency;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Shipping\Api\ShippingInterface;
use QUI\ERP\User as ErpUser;
use QUI\Interfaces\Users\User;
use RuntimeException;

final class OrderDouble extends AbstractOrder
{
    public array $history = [];
    public ?User $updateUser = null;
    public string $globalProcessIdValue = 'phpunit-global-process';
    public string $uuidValue = 'phpunit-order-uuid';
    public ?ShippingInterface $Shipping = null;
    public ?Calculations $PriceCalculation = null;
    public ?Currency $CurrencyValue = null;
    public ?ErpUser $CustomerValue = null;
    public bool $successfulStatusSet = false;
    public int $refreshCount = 0;

    public function __construct()
    {
    }

    public function clear(?User $PermissionUser = null): void
    {
    }

    public function refresh(): void
    {
        $this->refreshCount++;
    }

    public function update(?User $PermissionUser = null): void
    {
        $this->updateUser = $PermissionUser;
    }

    public function delete(?User $PermissionUser = null): void
    {
    }

    public function isPosted(): bool
    {
        return false;
    }

    public function getInvoice(): Invoice|InvoiceTemporary|null
    {
        return null;
    }

    public function hasInvoice(): bool
    {
        return false;
    }

    public function getGlobalProcessId(): string
    {
        return $this->globalProcessIdValue;
    }

    public function getUUID(): string
    {
        return $this->uuidValue;
    }

    public function getHash(): string
    {
        return $this->uuidValue;
    }

    public function setPaymentStatus(int $status, bool $force = false): void
    {
    }

    public function getPriceCalculation(): Calculations
    {
        if ($this->PriceCalculation === null) {
            throw new RuntimeException('No price calculation configured for test order.');
        }

        return $this->PriceCalculation;
    }

    public function getCurrency(): Currency
    {
        if ($this->CurrencyValue === null) {
            throw new RuntimeException('No currency configured for test order.');
        }

        return $this->CurrencyValue;
    }

    public function getCustomer(): ErpUser
    {
        if ($this->CustomerValue === null) {
            throw new RuntimeException('No customer configured for test order.');
        }

        return $this->CustomerValue;
    }

    public function setSuccessfulStatus(): void
    {
        $this->successfulStatusSet = true;
    }

    public function setShipping(ShippingInterface $Shipping): void
    {
        $this->Shipping = $Shipping;
    }

    public function useArticles(ArticleList $Articles): void
    {
        $this->Articles = $Articles;
    }

    public function addHistory(string $message): void
    {
        $this->history[] = $message;
    }

    protected function calculatePayments(): void
    {
    }

    protected function saveFrontendMessages(): void
    {
    }
}
