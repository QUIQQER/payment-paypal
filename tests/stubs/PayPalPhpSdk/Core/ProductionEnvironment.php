<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\PhpSdk\Core;

class ProductionEnvironment
{
    public function __construct(
        public mixed $clientId,
        public mixed $clientSecret
    ) {
    }
}
