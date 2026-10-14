<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\Api\OrderAmountValidator;

final class OrderAmountValidatorTest extends TestCase
{
    public function testCurrencyMismatchCannotBeHiddenByEqualNumericAmounts(): void
    {
        $amount = $this->amount('10.00');
        $items = $this->items('10.00', 1);
        $items[0]['unit_amount']['currency_code'] = 'USD';
        self::assertFalse(OrderAmountValidator::isConsistent($amount, $items));

        $amount['breakdown']['item_total']['currency_code'] = 'USD';
        self::assertFalse(OrderAmountValidator::isConsistent($amount, $this->items('10.00', 1)));
    }

    #[DataProvider('invalidQuantities')]
    public function testInvalidQuantitiesAreRejected(mixed $quantity): void
    {
        self::assertFalse(OrderAmountValidator::isConsistent(
            $this->amount('10.00'),
            $this->items('10.00', $quantity)
        ));
    }

    public static function invalidQuantities(): iterable
    {
        foreach ([null, false, true, [], 0, -1, 1.5, INF, NAN, '01', '1.0', '1e0', '1x', '10000000000'] as $quantity) {
            yield [$quantity];
        }
    }

    public function testNumericAndStringWholeQuantitiesAreSupported(): void
    {
        foreach ([1, 1.0, '1'] as $quantity) {
            self::assertTrue(OrderAmountValidator::isConsistent(
                $this->amount('10.00'),
                $this->items('10.00', $quantity)
            ));
        }
    }

    #[DataProvider('invalidAmounts')]
    public function testMalformedOrUnrepresentableMoneyIsRejected(mixed $value): void
    {
        self::assertFalse(OrderAmountValidator::isConsistent(
            $this->amount('10.00'),
            $this->items($value, 1)
        ));
    }

    public static function invalidAmounts(): iterable
    {
        foreach ([null, false, [], 10, '-10.00', '10,00', '1e1', '10.001', "10.00\n", '999999999999999999999.00'] as $value) {
            yield [$value];
        }
    }

    public function testMultiplicationAndAdditionOverflowAreRejected(): void
    {
        $largePrice = intdiv(PHP_INT_MAX, 100) . '.00';
        self::assertFalse(OrderAmountValidator::isConsistent(
            $this->amount('10.00'),
            $this->items($largePrice, 2)
        ));
        self::assertFalse(OrderAmountValidator::isConsistent(
            $this->amount('10.00'),
            array_merge($this->items($largePrice, 1), $this->items($largePrice, 1))
        ));

        $amount = $this->amount($largePrice);
        $amount['breakdown']['tax_total'] = [
            'currency_code' => 'EUR',
            'value' => '100.00'
        ];
        self::assertFalse(OrderAmountValidator::isConsistent($amount, $this->items($largePrice, 1)));
    }

    public function testUnknownBreakdownCannotBypassTheSumCheck(): void
    {
        $amount = $this->amount('10.00');
        $amount['breakdown']['unexpected'] = [
            'currency_code' => 'EUR',
            'value' => '1.00'
        ];
        self::assertFalse(OrderAmountValidator::isConsistent($amount, $this->items('10.00', 1)));
    }

    private function amount(string $value): array
    {
        return [
            'currency_code' => 'EUR',
            'value' => $value,
            'breakdown' => [
                'item_total' => [
                    'currency_code' => 'EUR',
                    'value' => $value
                ]
            ]
        ];
    }

    private function items(mixed $value, mixed $quantity): array
    {
        return [[
            'unit_amount' => [
                'currency_code' => 'EUR',
                'value' => $value
            ],
            'quantity' => $quantity
        ]];
    }
}
