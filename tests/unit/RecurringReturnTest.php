<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RecurringReturnTest extends TestCase
{
    #[DataProvider('redirectProvider')]
    public function testReturnPageOnlyAcceptsLocalPaths(mixed $redirect, string $expected): void
    {
        $html = $this->renderReturnPage([
            'redirect' => $redirect,
            'status' => 'success',
            'orderHash' => 'synthetic-order'
        ]);

        self::assertSame($expected, $this->getRedirect($html));
        self::assertStringContainsString('status: "success"', $html);
        self::assertStringContainsString('orderHash: "synthetic-order"', $html);
    }

    /** @return iterable<string, array{mixed, string}> */
    public static function redirectProvider(): iterable
    {
        yield 'homepage' => ['/', '/'];
        yield 'order finish' => ['/checkout/finish/order-id', '/checkout/finish/order-id'];
        yield 'query and fragment' => [
            '/de/bestellung/processing?orderHash=synthetic#finish',
            '/de/bestellung/processing?orderHash=synthetic#finish'
        ];
        yield 'encoded path' => ['/shop%20de/checkout', '/shop%20de/checkout'];
        yield 'colon in local path' => ['/checkout:finish', '/checkout:finish'];
        yield 'URL inside local query' => [
            '/checkout?reference=https%3A%2F%2Fexample.test',
            '/checkout?reference=https%3A%2F%2Fexample.test'
        ];
        yield 'javascript URL' => ['javascript:void(document.body.dataset.audit=1)', '/'];
        yield 'mixed-case active scheme' => ['JaVaScRiPt:void(0)', '/'];
        yield 'data URL' => ['data:text/html,<script>audit()</script>', '/'];
        yield 'file URL' => ['file:///fixture-only', '/'];
        yield 'external HTTPS' => ['https://attacker.invalid/fixture', '/'];
        yield 'external HTTP' => ['http://attacker.invalid/fixture', '/'];
        yield 'protocol-relative URL' => ['//attacker.invalid/fixture', '/'];
        yield 'extra leading slash' => ['///attacker.invalid/fixture', '/'];
        yield 'backslash authority' => ["/\\attacker.invalid/fixture", '/'];
        yield 'backslashes in path' => ["/checkout\\fixture", '/'];
        yield 'leading whitespace' => [' /checkout', '/'];
        yield 'newline in authority' => ["/\n/attacker.invalid/fixture", '/'];
        yield 'tab in authority' => ["/\t/attacker.invalid/fixture", '/'];
        yield 'carriage return' => ["/checkout\r/fixture", '/'];
        yield 'NUL byte' => ["/checkout\0/fixture", '/'];
        yield 'DEL byte' => ["/checkout\x7f/fixture", '/'];
        yield 'relative path' => ['checkout/finish', '/'];
        yield 'query-only target' => ['?target=checkout', '/'];
        yield 'empty target' => ['', '/'];
        yield 'array target' => [['javascript:void(0)'], '/'];
        yield 'array containing local path' => [['/checkout'], '/'];
        yield 'integer target' => [123, '/'];
        yield 'null target' => [null, '/'];
    }

    public function testMissingRedirectDefaultsToHomepage(): void
    {
        $html = $this->renderReturnPage([]);

        self::assertSame('/', $this->getRedirect($html));
        self::assertStringContainsString('status: "error"', $html);
    }

    public function testRedirectCannotEscapeInlineScript(): void
    {
        $redirect = '/</script><script>syntheticAudit()</script>';
        $html = $this->renderReturnPage(['redirect' => $redirect]);

        self::assertSame(1, substr_count($html, '</script>'));
        self::assertSame($redirect, $this->getRedirect($html));
    }

    private function getRedirect(string $html): string
    {
        self::assertSame(1, preg_match('/redirect:\s*("(?:[^"\\\\]|\\\\.)*")/', $html, $matches));
        $redirect = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsString($redirect);

        return $redirect;
    }

    /** @param array<string, mixed> $query */
    private function renderReturnPage(array $query): string
    {
        $previousGet = $_GET;
        $_GET = $query;
        ob_start();

        try {
            require dirname(__DIR__, 2) . '/bin/recurringReturn.php';

            return (string)ob_get_contents();
        } finally {
            ob_end_clean();
            $_GET = $previousGet;
        }
    }
}
