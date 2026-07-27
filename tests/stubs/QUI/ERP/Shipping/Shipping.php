<?php

declare(strict_types=1);

namespace QUI\ERP\Shipping;

use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Shipping\Types\ShippingEntry;

final class Shipping
{
    private static ?self $Instance = null;
    private ?ShippingEntry $Shipping = null;

    public static function getInstance(): self
    {
        if (self::$Instance === null) {
            self::$Instance = new self();
        }

        return self::$Instance;
    }

    public function setShipping(?ShippingEntry $Shipping): void
    {
        $this->Shipping = $Shipping;
    }

    public function getShippingByObject(AbstractOrder $Order): ?ShippingEntry
    {
        return $this->Shipping;
    }
}
