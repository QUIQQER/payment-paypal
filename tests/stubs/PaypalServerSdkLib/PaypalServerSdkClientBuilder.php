<?php

declare(strict_types=1);

namespace PaypalServerSdkLib;

use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;

final class PaypalServerSdkClientBuilder
{
    public static function init(): self
    {
        return new self();
    }

    public function clientCredentialsAuthCredentials(
        ClientCredentialsAuthCredentialsBuilder $Credentials
    ): self {
        return $this;
    }

    public function environment(string $environment): self
    {
        return $this;
    }

    public function build(): PaypalServerSdkClient
    {
        return new PaypalServerSdkClient();
    }
}
