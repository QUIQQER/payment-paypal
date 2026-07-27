<?php

namespace QUI\ERP\Order\Controls;

if (!class_exists(AbstractOrderingStep::class, false)) {
    abstract class AbstractOrderingStep extends \QUI\Control
    {
        public function getOrder(): ?\QUI\ERP\Order\AbstractOrder
        {
            return null;
        }

        public function getName(): string
        {
            return '';
        }
    }
}
