<?php

declare(strict_types=1);

spl_autoload_register(
    static function (string $class): void {
        $prefixes = [
            'QUITests\\ERP\\Payments\\PayPal\\Unit\\' => __DIR__ . '/unit/',
            'QUITests\\ERP\\Payments\\PayPal\\Integration\\' => __DIR__ . '/integration/',
            'QUI\\ERP\\Payments\\PayPal\\' => __DIR__ . '/../src/QUI/ERP/Payments/PayPal/'
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
