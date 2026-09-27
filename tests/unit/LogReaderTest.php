<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use QUI\ERP\Payments\PayPal\LogReader;
use RuntimeException;

final class LogReaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/paypal-log-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testListsOnlyPaypalLogsAndDefaultsToNewest(): void
    {
        file_put_contents($this->directory . '/paypal_api-2026-09-26.log', 'older');
        file_put_contents($this->directory . '/paypal_api-2026-09-27.log', 'NOT_AUTHORIZED debug_id=730b69995797f');
        file_put_contents($this->directory . '/error-2026-09-27.log', 'unrelated private log');
        $Reader = new LogReader($this->directory);
        $result = $Reader->read();
        self::assertSame(['paypal_api-2026-09-27.log', 'paypal_api-2026-09-26.log'], $result['files']);
        self::assertSame('paypal_api-2026-09-27.log', $result['selected']);
        self::assertSame('NOT_AUTHORIZED debug_id=730b69995797f', $result['data']);
        self::assertFalse($result['truncated']);
        self::assertSame('older', $Reader->read('paypal_api-2026-09-26.log')['data']);
    }

    public function testMissingDirectoryAndEmptyFileReturnEmptyContent(): void
    {
        self::assertSame([], (new LogReader($this->directory . '/missing'))->read()['files']);
        file_put_contents($this->directory . '/paypal_api-2026-09-27.log', '');
        $result = (new LogReader($this->directory))->read();
        self::assertSame('', $result['data']);
        self::assertFalse($result['truncated']);
    }

    public function testLargeLogReturnsOnlyBoundedTail(): void
    {
        file_put_contents(
            $this->directory . '/paypal_api-2026-09-27.log',
            'OLD-ENTRY' . str_repeat("\nlog line", 40000) . "\nLATEST-ENTRY\n"
        );
        $result = (new LogReader($this->directory))->read();
        self::assertTrue($result['truncated']);
        self::assertLessThanOrEqual(LogReader::MAX_BYTES, strlen($result['data']));
        self::assertStringNotContainsString('OLD-ENTRY', $result['data']);
        self::assertStringEndsWith("LATEST-ENTRY\n", $result['data']);
    }

    public function testRejectsTraversalAndUnrelatedFilenames(): void
    {
        $Reader = new LogReader($this->directory);

        foreach (
            ['../paypal_api-2026-09-27.log', '/etc/passwd', 'error-2026-09-27.log',
            "paypal_api-2026-09-27.log\n", 'paypal_api-2026-09-27.log.gz'] as $file
        ) {
            try {
                $Reader->read($file);
                self::fail('Unsafe filename accepted: ' . $file);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSymlinksAreNotListedOrReadable(): void
    {
        file_put_contents($this->directory . '/private.txt', 'private contents');
        symlink($this->directory . '/private.txt', $this->directory . '/paypal_api-2026-09-27.log');
        $Reader = new LogReader($this->directory);
        self::assertSame([], $Reader->read()['files']);
        $this->expectException(RuntimeException::class);
        $Reader->read('paypal_api-2026-09-27.log');
    }

    public function testLogWithInvalidUtf8CanBeSerializedForAjax(): void
    {
        file_put_contents($this->directory . '/paypal_api-2026-09-27.log', "invalid \xFF\n");
        $result = (new LogReader($this->directory))->read();
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }
}
