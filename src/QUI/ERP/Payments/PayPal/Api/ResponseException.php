<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\Api;

use RuntimeException;

final class ResponseException extends RuntimeException
{
    /** @param array<string, mixed> $diagnostics */
    public function __construct(string $message, private readonly array $diagnostics)
    {
        parent::__construct($message, (int)($diagnostics['httpStatus'] ?? 0));
    }

    /** @return array<string, mixed> */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }
}
