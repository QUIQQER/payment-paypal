<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\PayPalException;

final class AccountContextTest extends TestCase
{
    public function testContextIsStableForTheSameApplicationAndMode(): void
    {
        self::assertSame(
            AccountContext::createHash('client-id', true),
            AccountContext::createHash('client-id', true)
        );
    }

    public function testDifferentApplicationsHaveDifferentContexts(): void
    {
        self::assertNotSame(
            AccountContext::createHash('first-client-id', true),
            AccountContext::createHash('second-client-id', true)
        );
    }

    public function testSandboxAndLiveHaveDifferentContexts(): void
    {
        self::assertNotSame(
            AccountContext::createHash('client-id', true),
            AccountContext::createHash('client-id', false)
        );
    }

    public function testMissingResourcesAreRecognizedByHttpStatus(): void
    {
        self::assertTrue(
            AccountContext::isMissingResource(
                new PayPalException('Missing', 404)
            )
        );
        self::assertFalse(
            AccountContext::isMissingResource(
                new PayPalException('Unavailable', 503)
            )
        );
    }
}
