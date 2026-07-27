<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Shipping\Api\ShippingInterface;
use QUI\Interfaces\Users\User;

final class OrderDouble extends AbstractOrder
{
    public array $history = [];
    public ?User $updateUser = null;
    public string $globalProcessIdValue = 'phpunit-global-process';
    public string $uuidValue = 'phpunit-order-uuid';
    public ?ShippingInterface $Shipping = null;

    public function __construct()
    {
    }

    public function clear(?User $PermissionUser = null): void
    {
    }

    public function refresh(): void
    {
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

    public function setShipping(ShippingInterface $Shipping): void
    {
        $this->Shipping = $Shipping;
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
