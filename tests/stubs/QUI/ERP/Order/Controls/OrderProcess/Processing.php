<?php

namespace QUI\ERP\Order\Controls\OrderProcess;

if (!class_exists(Processing::class)) {
    class Processing extends \QUI\ERP\Order\Controls\AbstractOrderingStep
    {
        public function setContent(string $content): void
        {
        }

        public function setTitle(string $title): void
        {
        }
    }
}
