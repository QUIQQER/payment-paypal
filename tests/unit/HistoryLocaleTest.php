<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function preg_match;
use function preg_match_all;
use function simplexml_load_file;
use function str_ends_with;
use function token_get_all;

use const T_COMMENT;
use const T_DOC_COMMENT;

final class HistoryLocaleTest extends TestCase
{
    public function testHistoryCallsDoNotUseHardCodedMessages(): void
    {
        foreach ($this->sourceFiles() as $file) {
            $source = $this->sourceWithoutComments($file);

            self::assertSame(
                0,
                preg_match('/->addHistory\\s*\\(\\s*[\'"]/', $source),
                $file . ' writes a hard-coded history message.'
            );
        }
    }

    public function testHistoryLocaleKeysHaveGermanAndEnglishTranslations(): void
    {
        $LocaleXml = simplexml_load_file(
            dirname(__DIR__, 2) . '/locale.xml'
        );

        self::assertInstanceOf(SimpleXMLElement::class, $LocaleXml);

        foreach ($this->sourceFiles() as $file) {
            $source = $this->sourceWithoutComments($file);
            preg_match_all(
                "/Utils::getHistoryText\\(\\s*'([^']+)'/",
                $source,
                $matches
            );

            foreach ($matches[1] as $historyKey) {
                $Locales = $LocaleXml->xpath(
                    "/locales/groups/locale[@name='history.{$historyKey}']"
                );

                self::assertIsArray($Locales);
                self::assertCount(
                    1,
                    $Locales,
                    "Missing locale history.{$historyKey} used in {$file}."
                );
                self::assertNotSame('', (string)$Locales[0]->de);
                self::assertNotSame('', (string)$Locales[0]->en);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                dirname(__DIR__, 2) . '/src'
            )
        );

        /** @var SplFileInfo $File */
        foreach ($iterator as $File) {
            if (!$File->isFile() || !str_ends_with($File->getFilename(), '.php')) {
                continue;
            }

            $files[] = $File->getPathname();
        }

        return $files;
    }

    private function sourceWithoutComments(string $file): string
    {
        $source = file_get_contents($file);
        self::assertIsString($source);
        $result = '';

        foreach (token_get_all($source) as $token) {
            if (
                is_array($token)
                && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ) {
                continue;
            }

            $result .= is_array($token) ? $token[1] : $token;
        }

        return $result;
    }
}
