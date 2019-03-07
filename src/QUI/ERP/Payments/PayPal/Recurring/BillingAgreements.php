<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use QUI\ERP\Accounting\Payments\Transactions\Transaction;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\PayPalException;

/**
 * Class BillingAgreements
 *
 * Handler for PayPal Billing Agreement management
 */
class BillingAgreements
{
    /**
     * @var QUI\ERP\Payments\PayPal\Payment
     */
    protected static $Payment = null;

    /**
     * Get data of all available Billing Agreements
     *
     * @return array
     */
    public static function getBillingAgreements()
    {

    }

    /**
     * Make a PayPal REST API request
     *
     * @param string $request - Request type (see self::PAYPAL_REQUEST_TYPE_*)
     * @param array $body - Request data
     * @param AbstractOrder|Transaction|array $TransactionObj - Object that contains necessary request data
     * ($Order has to have the required paymentData attributes for the given $request value!)
     * @return array|false - Response body or false on error
     *
     * @throws PayPalException
     */
    protected static function payPalApiRequest($request, $body, $TransactionObj)
    {
        if (is_null(self::$Payment)) {
            self::$Payment = new QUI\ERP\Payments\PayPal\Payment();
        }

        return self::$Payment->payPalApiRequest($request, $body, $TransactionObj);
    }
}
