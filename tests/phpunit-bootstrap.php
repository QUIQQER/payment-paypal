<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/package-autoload.php';

// CI does not install optional packages. Mark Shipping as available so its test shims
// exercise the same PayPal code paths locally and in CI.
$PackageManager = QUI::getPackageManager();
$Installed = new ReflectionProperty($PackageManager, 'installed');
$installed = $Installed->getValue($PackageManager);
$installed['quiqqer/shipping'] = true;
$Installed->setValue($PackageManager, $installed);
