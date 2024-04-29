<?php

namespace QUI\ERP\Payments\PayPal;

use QUI;
use QUI\ERP\Accounting\CalculationValue;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Shipping\Shipping;
use QUI\Exception;

use function mb_strtoupper;

/**
 * Class Utils
 *
 * Utility methods for quiqqer/payment-paypal
 */
class Utils
{
    /**
     * Format a price for PayPal API use
     *
     * @param float|int $amount
     * @return string - Amount with trailing zeroes
     */
    public static function formatPrice(float|int $amount): string
    {
        $AmountValue = new CalculationValue($amount, null, 2);
        $amount = $AmountValue->get();

        return sprintf("%.2F", $amount);
    }

    /**
     * Get base URL (with host) for current Project
     *
     * @return string
     */
    public static function getProjectUrl(): string
    {
        try {
            $url = QUI::getRewrite()->getProject()->get(1)->getUrlRewrittenWithHost();
            return rtrim($url, '/');
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
            return '';
        }
    }

    /**
     * Save Order with SystemUser
     *
     * @param AbstractOrder $Order
     * @return void
     * @throws Exception
     */
    public static function saveOrder(AbstractOrder $Order): void
    {
        $Order->update(QUI::getUsers()->getSystemUser());
    }

    /**
     * Get translated history text
     *
     * @param string $context
     * @param array $data (optional) - Additional data for translation
     * @return string
     */
    public static function getHistoryText(string $context, array $data = []): string
    {
        return QUI::getLocale()->get('quiqqer/payment-paypal', 'history.' . $context, $data);
    }

    /**
     * Get shipping address data by order that is used in the PayPal API workflow.
     *
     * @param AbstractOrder $Order
     * @return array|false - Shipping address data as array or false if shipping address cannot be determined
     */
    public static function getPayPalShippingAddressDataByOrder(AbstractOrder $Order): bool|array
    {
        if (!QUI::getPackageManager()->isInstalled('quiqqer/shipping')) {
            return false;
        }

        $Shipping = Shipping::getInstance()->getShippingByObject($Order);

        if (!$Shipping || $Shipping->getAddress()) {
            return false;
        }

        $ShippingAddress = $Shipping->getAddress();

        $shippingAddress = [
            'recipient_name' => $ShippingAddress->getName()
        ];

        if ($ShippingAddress->getAttribute('street_no')) {
            $shippingAddress['line1'] = $ShippingAddress->getAttribute('street_no');
        }

        if ($ShippingAddress->getAttribute('city')) {
            $shippingAddress['city'] = $ShippingAddress->getAttribute('city');
        }

        if ($ShippingAddress->getAttribute('zip')) {
            $shippingAddress['postal_code'] = $ShippingAddress->getAttribute('zip');
        }

        if ($ShippingAddress->getPhone()) {
            $shippingAddress['phone'] = $ShippingAddress->getPhone();
        }

        try {
            $shippingAddress['country_code'] = mb_strtoupper(
                $ShippingAddress->getCountry()->getCode()
            );
        } catch (\Exception $Exception) {
            QUI\System\Log::writeDebugException($Exception);
        }

        return $shippingAddress;
    }

    /**
     * Get shipping costs by order
     *
     * @param AbstractOrder $Order
     * @return QUI\ERP\Products\Interfaces\PriceFactorInterface|false - Shipping cost (2 digit precision) or false if costs cannot be determined
     */
    public static function getShippingCostsByOrder(AbstractOrder $Order
    ): bool|QUI\ERP\Products\Interfaces\PriceFactorInterface {
        if (!QUI::getPackageManager()->isInstalled('quiqqer/shipping')) {
            return false;
        }

        return Shipping::getInstance()->getShippingPriceFactor($Order);
    }

    /**
     * Get shipping method that is used in express orders
     *
     * @param AbstractOrder $Order
     * @return QUI\ERP\Shipping\Types\ShippingEntry|false
     */
    public static function getDefaultExpressShipping(AbstractOrder $Order): QUI\ERP\Shipping\Types\ShippingEntry|bool
    {
        $shippingEntries = Shipping::getInstance()->getValidShippingEntries($Order);

        if (empty($shippingEntries)) {
            return false;
        }

        return $shippingEntries[0];
    }
}
