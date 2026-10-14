<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\MCP;

/** Validate before passing AI-supplied values to typed domain APIs. */
final class Input
{
    public static function integer(mixed $value, string $name, int $minimum, int $maximum): int
    {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidInputException($name);
        }

        return $value;
    }

    public static function text(mixed $value, string $name, int $maximum, bool $emptyAllowed = true): string
    {
        if (
            !is_string($value)
            || strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
            || preg_match('//u', $value) !== 1
            || (!$emptyAllowed && trim($value) === '')
        ) {
            throw new InvalidInputException($name);
        }

        return $value;
    }

    public static function pattern(mixed $value, string $name, string $pattern): string
    {
        if (!is_string($value) || preg_match($pattern, $value) !== 1) {
            throw new InvalidInputException($name);
        }

        return $value;
    }

    /** @param list<string> $allowed */
    public static function choice(mixed $value, string $name, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidInputException($name);
        }

        return $value;
    }

    public static function subscriptionId(mixed $value): string
    {
        return self::pattern($value, 'subscriptionId', '/\AI-[A-Z0-9]{1,126}\z/D');
    }
}
