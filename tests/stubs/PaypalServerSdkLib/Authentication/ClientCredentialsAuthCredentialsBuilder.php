<?php

declare(strict_types=1);

namespace PaypalServerSdkLib\Authentication;

final class ClientCredentialsAuthCredentialsBuilder
{
    public static function init(string $clientId, string $clientSecret): self
    {
        return new self();
    }
}
