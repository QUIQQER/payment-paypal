<?php

use QUI\ERP\Payments\PayPal\Diagnostics;

QUI::getAjax()->registerFunction(
    'package_quiqqer_payment-paypal_ajax_getSdkConfig',
    static fn (): array => Diagnostics::getSdkConfig(),
    []
);
