<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\MCP;

/** Only locally validated field names are safe to include in an MCP error. */
final class InvalidInputException extends \InvalidArgumentException
{
}
