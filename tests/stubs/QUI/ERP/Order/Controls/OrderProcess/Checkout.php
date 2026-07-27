<?php

namespace QUI\ERP\Order\Controls\OrderProcess;

if (!class_exists(Checkout::class, false)) {
    class Checkout extends \QUI\ERP\Order\Controls\AbstractOrderingStep
    {
    }
}
