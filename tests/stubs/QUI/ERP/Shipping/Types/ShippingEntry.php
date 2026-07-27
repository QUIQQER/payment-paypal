<?php

declare(strict_types=1);

namespace QUI\ERP\Shipping\Types;

use QUI\ERP\Address;

final class ShippingEntry
{
    public function __construct(private readonly ?Address $Address)
    {
    }

    public function getAddress(): ?Address
    {
        return $this->Address;
    }
}
