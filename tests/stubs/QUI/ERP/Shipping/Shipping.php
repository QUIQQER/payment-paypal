<?php

declare(strict_types=1);

namespace QUI\ERP\Shipping;

use QUI\ERP\Accounting\PriceFactors\Factor;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Products\Interfaces\PriceFactorInterface;
use QUI\ERP\Shipping\Types\ShippingEntry;

final class Shipping
{
    private static ?self $Instance = null;
    private ?ShippingEntry $Shipping = null;
    private Factor|PriceFactorInterface|null $PriceFactor = null;
    private array $ShippingEntries = [];

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

    public function setShippingPriceFactor(Factor|PriceFactorInterface|null $PriceFactor): void
    {
        $this->PriceFactor = $PriceFactor;
    }

    public function getShippingPriceFactor(AbstractOrder $Order): Factor|PriceFactorInterface|null
    {
        return $this->PriceFactor;
    }

    public function setValidShippingEntries(array $ShippingEntries): void
    {
        $this->ShippingEntries = $ShippingEntries;
    }

    public function getValidShippingEntries(AbstractOrder $Order): array
    {
        return $this->ShippingEntries;
    }
}
