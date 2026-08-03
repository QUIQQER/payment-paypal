<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/package-autoload.php';

// Subscription account scoping requires a client ID. Keep the test suite
// independent from credentials configured in the executing QUIQQER system.
$PayPalConfig = QUI\ERP\Payments\PayPal\Settings::getConfig();

foreach (['sandbox_client_id', 'client_id'] as $clientIdSetting) {
    $clientId = $PayPalConfig->get('api', $clientIdSetting);

    if (!is_string($clientId) || $clientId === '') {
        $PayPalConfig->setValue(
            'api',
            $clientIdSetting,
            'phpunit-' . $clientIdSetting
        );
    }
}

// CI does not install optional packages. Mark Shipping as available so its test shims
// exercise the same PayPal code paths locally and in CI.
$PackageManager = QUI::getPackageManager();
$Installed = new ReflectionProperty($PackageManager, 'installed');
$installed = $Installed->getValue($PackageManager);
$installed['quiqqer/shipping'] = true;
$Installed->setValue($PackageManager, $installed);
