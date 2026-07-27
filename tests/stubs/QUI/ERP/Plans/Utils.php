<?php

namespace QUI\ERP\Plans;

if (!class_exists(Utils::class)) {
    class Utils
    {
        public static function compareDateIntervals(
            \DateInterval $IntervalA,
            \DateInterval $IntervalB
        ): int {
            return 0;
        }

        /**
         * @return array<string, mixed>
         */
        public static function getPlanDetailsFromOrder(
            \QUI\ERP\Order\OrderInterface $Order
        ): array {
            return [];
        }

        /**
         * @return array<string, mixed>
         */
        public static function getPlanDetailsFromProduct(
            \QUI\ERP\Products\Product\Product $Product
        ): array {
            return [];
        }

        public static function isPlanArticle(mixed $Article): bool
        {
            return false;
        }

        public static function isPlanOrder(
            \QUI\ERP\Order\OrderInterface $Order
        ): bool {
            return false;
        }

        public static function parseIntervalFromDuration(
            string $duration
        ): \DateInterval {
            return new \DateInterval('P1D');
        }
    }
}
