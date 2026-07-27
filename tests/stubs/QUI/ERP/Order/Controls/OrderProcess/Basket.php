<?php

namespace QUI\ERP\Order\Controls\OrderProcess;

if (!class_exists(Basket::class, false)) {
    class Basket extends \QUI\ERP\Order\Controls\AbstractOrderingStep
    {
    }
}
