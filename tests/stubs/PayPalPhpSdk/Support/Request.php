<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\PhpSdk\Support;

class Request
{
    public array $body = [];
    public array $arguments = [];
    public array $parameters = [];

    public function __construct(mixed ...$arguments)
    {
        $this->arguments = $arguments;
    }

    public function __call(string $name, array $arguments): static
    {
        $this->parameters[$name] = $arguments[0] ?? null;

        return $this;
    }
}
