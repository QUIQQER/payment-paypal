<?php

namespace QUI\ERP\Payments\PayPal\Recurring\Subscriptions;

use QUI\ERP\Payments\PayPal\PayPalException;

/**
 * A failure from a resource endpoint, distinct from OAuth or transport failures.
 */
class RequestException extends PayPalException
{
    public function __construct(
        string $message,
        int $status,
        public readonly string $responseName
    ) {
        parent::__construct($message, $status);
    }
}
