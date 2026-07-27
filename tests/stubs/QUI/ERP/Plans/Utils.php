<?php

namespace QUI\ERP\Plans;

if (!class_exists(Utils::class, false)) {
    class Utils
    {
        public static function compareDateIntervals(
            \DateInterval $IntervalA,
            \DateInterval $IntervalB
        ): int {
            $DateA = (new \DateTimeImmutable())->add($IntervalA);
            $DateB = (new \DateTimeImmutable())->add($IntervalB);

            return $DateA <=> $DateB;
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
            return [
                'auto_extend' => $Product->getFieldValue(Handler::FIELD_AUTO_EXTEND),
                'duration_interval' => $Product->getFieldValue(Handler::FIELD_DURATION),
                'notice_period' => $Product->getFieldValue(Handler::FIELD_NOTICE_PERIOD),
                'invoice_interval' => $Product->getFieldValue(Handler::FIELD_INVOICE_INTERVAL),
                'min_duration_interval' => $Product->getFieldValue(Handler::FIELD_MIN_DURATION)
            ];
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
        ): \DateInterval | false {
            if ($duration === '' || $duration === 'unlimited') {
                return false;
            }

            [$value, $unit] = explode('-', $duration, 2);
            $period = match ($unit) {
                'week' => 'W',
                'month' => 'M',
                'year' => 'Y',
                default => 'D'
            };

            return new \DateInterval('P' . $value . $period);
        }
    }
}
