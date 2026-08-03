<?php

namespace QUI\ERP\Payments\PayPal;

use RuntimeException;

use function hash;
use function is_string;

/**
 * Identifies the PayPal REST application and mode used for subscriptions.
 */
final class AccountContext
{
    /**
     * Return a stable hash for the currently configured PayPal application and mode.
     *
     * The client ID is stable across client-secret rotations. The secret itself is
     * deliberately not persisted or included in the context.
     *
     * @return string
     */
    public static function getHash(): string
    {
        $sandbox = (bool)Provider::getApiSetting('sandbox');
        $clientId = Provider::getApiSetting(
            $sandbox ? 'sandbox_client_id' : 'client_id'
        );

        if (!is_string($clientId) || $clientId === '') {
            throw new RuntimeException('PayPal client ID is missing.');
        }

        return self::createHash($clientId, $sandbox);
    }

    /**
     * @param string $clientId
     * @param bool $sandbox
     * @return string
     */
    public static function createHash(string $clientId, bool $sandbox): string
    {
        return hash(
            'sha256',
            'paypal-subscriptions-account-context-v1|'
            . $clientId
            . '|'
            . ($sandbox ? 'sandbox' : 'live')
        );
    }

    /**
     * @param PayPalException $Exception
     * @return bool
     */
    public static function isMissingResource(PayPalException $Exception): bool
    {
        return $Exception->getCode() === 404;
    }
}
