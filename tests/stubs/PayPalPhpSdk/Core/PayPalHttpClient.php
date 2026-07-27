<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\PhpSdk\Core;

use Exception;
use QUI\ERP\Payments\PayPal\PhpSdk\Support\Request;

class PayPalHttpClient
{
    public mixed $result = null;
    public ?Exception $exception = null;

    /** @var list<Request> */
    public array $requests = [];

    public function __construct(mixed $Environment = null)
    {
    }

    /**
     * @return object{result: mixed}
     */
    public function execute(Request $Request): object
    {
        $this->requests[] = $Request;

        if ($this->exception instanceof Exception) {
            throw $this->exception;
        }

        return (object)[
            'result' => $this->result
        ];
    }
}
