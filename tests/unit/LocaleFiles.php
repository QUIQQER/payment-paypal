<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\Assert;
use SimpleXMLElement;

final class LocaleFiles
{
    /**
     * Load the same direct locale includes as the QUIQQER package importer.
     *
     * @return list<SimpleXMLElement>
     */
    public static function load(): array
    {
        $directory = dirname(__DIR__, 2);
        $Index = simplexml_load_file($directory . '/locale.xml');
        Assert::assertInstanceOf(SimpleXMLElement::class, $Index);
        $documents = [];

        if ($Index->xpath('//locales/groups/locale')) {
            $documents[] = $Index;
        }

        foreach ($Index->xpath('//locales/file') ?: [] as $File) {
            $path = $directory . '/' . ltrim((string)$File['file'], '/');
            Assert::assertFileExists($path);
            $Document = simplexml_load_file($path);
            Assert::assertInstanceOf(SimpleXMLElement::class, $Document);
            Assert::assertNotEmpty($Document->xpath('//locales/groups/locale'), $path);
            $documents[] = $Document;
        }

        Assert::assertNotEmpty($documents, 'No locale entries were loaded.');

        return $documents;
    }
}
