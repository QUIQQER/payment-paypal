<?php

declare(strict_types=1);

namespace PaypalServerSdkLib\Http;

class ApiResponse
{
    public function getStatusCode(): ?int
    {
        return 200;
    }

    public function getHeaders(): ?array
    {
        return [];
    }

    public function getBody(): mixed
    {
        return null;
    }

    public function getResult(): mixed
    {
        return null;
    }
}
