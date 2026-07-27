<?php

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefixes = [
            'QUITests\\ERP\\Payments\\PayPal\\Unit\\' => __DIR__ . '/unit/',
            'QUITests\\ERP\\Payments\\PayPal\\Integration\\' => __DIR__ . '/integration/',
            'QUI\\ERP\\Payments\\PayPal\\PhpSdk\\' => __DIR__ . '/stubs/PayPalPhpSdk/',
            'QUI\\ERP\\Payments\\PayPal\\' => __DIR__ . '/../src/QUI/ERP/Payments/PayPal/',
            'QUI\\ERP\\Accounting\\Invoice\\' => __DIR__ . '/stubs/QUI/ERP/Accounting/Invoice/',
            'QUI\\ERP\\Order\\' => __DIR__ . '/stubs/QUI/ERP/Order/',
            'QUI\\ERP\\Plans\\' => __DIR__ . '/stubs/QUI/ERP/Plans/',
            'QUI\\ERP\\Shipping\\' => __DIR__ . '/stubs/QUI/ERP/Shipping/',
            'PaypalServerSdkLib\\' => __DIR__ . '/stubs/PaypalServerSdkLib/'
        ];

        foreach ($prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = $directory . str_replace('\\', '/', $relativeClass) . '.php';

            if (is_file($file)) {
                require_once $file;
            }

            return;
        }
    }
);
