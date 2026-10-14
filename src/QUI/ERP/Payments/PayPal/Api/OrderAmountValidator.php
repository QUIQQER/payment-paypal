<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\Api;

/** Check the formatted basket that PayPal receives, using integer cents rather than floats. */
final class OrderAmountValidator
{
    /**
     * @param array<string, mixed> $amount
     * @param list<array<string, mixed>> $items
     */
    public static function isConsistent(array $amount, array $items): bool
    {
        $currency = $amount['currency_code'] ?? null;
        $breakdown = $amount['breakdown'] ?? null;

        if (
            !is_string($currency)
            || preg_match('/\A[A-Z]{3}\z/D', $currency) !== 1
            || !is_array($breakdown)
            || $items === []
        ) {
            return false;
        }

        $total = self::cents($amount, $currency);
        $itemTotal = self::cents($breakdown['item_total'] ?? null, $currency);

        if (
            $total === null
            || $itemTotal === null
        ) {
            return false;
        }

        $calculatedItemTotal = 0;

        foreach ($items as $item) {
            $unitAmount = self::cents($item['unit_amount'] ?? null, $currency);
            $quantity = self::quantity($item['quantity'] ?? null);

            if (
                $unitAmount === null
                || $quantity === null
                || $unitAmount > intdiv(PHP_INT_MAX - $calculatedItemTotal, $quantity)
            ) {
                return false;
            }

            $calculatedItemTotal += $unitAmount * $quantity;
        }

        if ($calculatedItemTotal !== $itemTotal) {
            return false;
        }

        $positiveFields = ['item_total', 'tax_total', 'shipping', 'handling', 'insurance'];
        $negativeFields = ['shipping_discount', 'discount'];
        $allowedFields = array_merge($positiveFields, $negativeFields);

        if (array_diff(array_keys($breakdown), $allowedFields) !== []) {
            return false;
        }

        $calculatedTotal = 0;

        foreach ($positiveFields as $field) {
            if (!array_key_exists($field, $breakdown)) {
                continue;
            }

            $value = self::cents($breakdown[$field], $currency);

            if (
                $value === null
                || $value > PHP_INT_MAX - $calculatedTotal
            ) {
                return false;
            }

            $calculatedTotal += $value;
        }

        foreach ($negativeFields as $field) {
            if (!array_key_exists($field, $breakdown)) {
                continue;
            }

            $value = self::cents($breakdown[$field], $currency);

            if (
                $value === null
                || $value > $calculatedTotal
            ) {
                return false;
            }

            $calculatedTotal -= $value;
        }

        return $calculatedTotal === $total;
    }

    private static function cents(mixed $amount, string $currency): ?int
    {
        if (
            !is_array($amount)
            || ($amount['currency_code'] ?? null) !== $currency
            || !is_string($amount['value'] ?? null)
            || preg_match('/\A[0-9]+\.[0-9]{2}\z/D', $amount['value']) !== 1
        ) {
            return null;
        }

        // The package currently formats PayPal amounts with two decimal places.
        $digits = ltrim(str_replace('.', '', $amount['value']), '0');
        $value = filter_var($digits === '' ? '0' : $digits, FILTER_VALIDATE_INT);

        return $value === false ? null : $value;
    }

    private static function quantity(mixed $quantity): ?int
    {
        if (is_int($quantity) || is_float($quantity)) {
            if (
                !is_finite((float)$quantity)
                || floor($quantity) !== (float)$quantity
            ) {
                return null;
            }

            $quantity = (string)$quantity;
        }

        if (
            !is_string($quantity)
            || preg_match('/\A[1-9][0-9]{0,9}\z/D', $quantity) !== 1
        ) {
            return null;
        }

        $value = filter_var($quantity, FILTER_VALIDATE_INT);

        return $value === false ? null : $value;
    }
}
