<?php

namespace QUI\ERP\Order\Utils;

if (!class_exists(Utils::class)) {
    class Utils
    {
        public static function getOrderProcessUrl(
            \QUI\Projects\Project $Project,
            ?\QUI\ERP\Order\Controls\AbstractOrderingStep $Step = null
        ): ?string {
            return null;
        }
    }
}
