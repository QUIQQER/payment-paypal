<?php

if (!defined('QUIQQER_SYSTEM')) {
    define('QUIQQER_SYSTEM', true);
}

if (!defined('QUIQQER_AJAX')) {
    define('QUIQQER_AJAX', true);
}

putenv("QUIQQER_OTHER_AUTOLOADERS=KEEP");

require_once __DIR__ . '/../../../../bootstrap.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/OrderInterface.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Exception.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Basket/Exception.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/AbstractOrder.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Order.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/OrderInProcess.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Controls/AbstractOrderingStep.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Controls/OrderProcess/Basket.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Controls/OrderProcess/Checkout.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Controls/OrderProcess/Finish.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Controls/OrderProcess/Processing.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Basket/Basket.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Basket/BasketGuest.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Basket/BasketOrder.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Handler.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/OrderProcess.php';
require_once __DIR__ . '/stubs/QUI/ERP/Order/Utils/Utils.php';
require_once __DIR__ . '/stubs/QUI/ERP/Accounting/Invoice/Invoice.php';
require_once __DIR__ . '/stubs/QUI/ERP/Accounting/Invoice/InvoiceTemporary.php';
require_once __DIR__ . '/stubs/QUI/ERP/Accounting/Invoice/InvoiceView.php';
require_once __DIR__ . '/stubs/QUI/ERP/Accounting/Invoice/Handler.php';
require_once __DIR__ . '/stubs/QUI/ERP/Plans/Handler.php';
require_once __DIR__ . '/stubs/QUI/ERP/Plans/Utils.php';
require_once __DIR__ . '/package-autoload.php';
