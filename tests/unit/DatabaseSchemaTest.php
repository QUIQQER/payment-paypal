<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

use function dirname;
use function simplexml_load_file;

final class DatabaseSchemaTest extends TestCase
{
    public function testSchemaUsesPortableFieldTypes(): void
    {
        $Xml = $this->loadSchema();
        $allowedTypes = [
            'boolean',
            'datetime',
            'integer',
            'string',
            'text'
        ];

        foreach ($Xml->global->table as $Table) {
            foreach ($Table->field as $Field) {
                self::assertContains((string)$Field['type'], $allowedTypes);
            }
        }
    }

    public function testTransactionProcessIdsAreNullable(): void
    {
        foreach (
            [
                'paypal_billing_agreement_transactions',
                'paypal_subscription_transactions'
            ] as $tableName
        ) {
            $Field = $this->getField($tableName, 'global_process_id');

            self::assertSame('true', (string)$Field['nullable']);
            self::assertSame('null', (string)$Field['default']);
        }
    }

    private function loadSchema(): SimpleXMLElement
    {
        $Xml = simplexml_load_file(dirname(__DIR__, 2) . '/database.xml');

        self::assertInstanceOf(SimpleXMLElement::class, $Xml);

        return $Xml;
    }

    private function getField(string $tableName, string $fieldName): SimpleXMLElement
    {
        $fields = $this->loadSchema()->xpath(
            "/database/global/table[@name='{$tableName}']/field[text()='{$fieldName}']"
        );

        self::assertIsArray($fields);
        self::assertCount(1, $fields);
        self::assertInstanceOf(SimpleXMLElement::class, $fields[0]);

        return $fields[0];
    }
}
