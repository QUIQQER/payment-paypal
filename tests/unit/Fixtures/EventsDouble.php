<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit\Fixtures;

use QUI\ERP\Payments\PayPal\Events;

final class EventsDouble extends Events
{
    protected static function isPlansInstalled(): bool
    {
        return false;
    }
}
