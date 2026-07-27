<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Invoice\Invoice;
use QUI\ERP\Accounting\Invoice\InvoiceTemporary;
use QUI\ERP\Order\AbstractOrder;
use QUI\Interfaces\Users\User;

final class OrderDouble extends AbstractOrder
{
    public array $history = [];
    public ?User $updateUser = null;

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

    public function setPaymentStatus(int $status, bool $force = false): void
    {
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
