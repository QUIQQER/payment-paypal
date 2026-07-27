<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Accounting\Payments\Types\Payment;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PaymentExpress;
use QUI\Users\Address;
use QUI\Users\User;

final class PaymentExpressDouble extends PaymentExpress
{
    public array|bool $payPalOrder = [];
    public false|Payment $ExpressPayment = false;
    public ?User $QuiqqerUser = null;
    public ?Address $PayPalAddress = null;
    public int $voidCount = 0;
    public int $saveCount = 0;
    public int $updateCount = 0;
    public int $shippingCount = 0;

    /**
     * @param array<string, mixed> $payPalOrder
     * @return array<string, mixed>
     */
    public function getPayerData(array $payPalOrder): array
    {
        return $this->getPayerDataFromPayPalOrder($payPalOrder);
    }

    public function createAddressForTest(
        array $payPalOrder,
        User $QuiqqerUser
    ): Address {
        return parent::getQuiqqerAddressFromPayPalOrder(
            $payPalOrder,
            $QuiqqerUser
        );
    }

    protected function getPayPalOrderDetails(AbstractOrder $Order): bool|array
    {
        return $this->payPalOrder;
    }

    protected function getExpressPayment(): false|Payment
    {
        return $this->ExpressPayment;
    }

    protected function getQuiqqerUser(string|int $userId): User
    {
        return $this->QuiqqerUser;
    }

    protected function getQuiqqerAddressFromPayPalOrder(
        array $payPalOrder,
        User $QuiqqerUser
    ): Address {
        return $this->PayPalAddress;
    }

    protected function reloadQuiqqerAddress(
        User $QuiqqerUser,
        string|int $addressId
    ): Address {
        return $this->PayPalAddress;
    }

    protected function voidPayPalOrder(AbstractOrder $Order): void
    {
        $this->voidCount++;
    }

    protected function saveOrder(AbstractOrder $Order): void
    {
        $this->saveCount++;
    }

    public function setDefaultShipping(AbstractOrder $Order): void
    {
        $this->shippingCount++;
    }

    public function updatePayPalOrder(AbstractOrder $Order): void
    {
        $this->updateCount++;
    }
}
