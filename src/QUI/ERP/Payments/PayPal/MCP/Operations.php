<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\MCP;

use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\Api\ConnectionTest;
use QUI\ERP\Payments\PayPal\LogReader;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Provider as PayPalProvider;
use QUI\ERP\Payments\PayPal\Recurring\BillingAgreements;
use QUI\ERP\Payments\PayPal\Recurring\BillingPlans;
use QUI\ERP\Payments\PayPal\Recurring\SubscriptionCleanup;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions;
use RuntimeException;

/** Structured administrative results using the existing PayPal domain APIs. */
class Operations
{
    /** @return array<string, mixed> */
    public function configuration(): array
    {
        $environments = [];

        foreach (['production' => '', 'sandbox' => 'sandbox_'] as $environment => $prefix) {
            $clientId = PayPalProvider::getApiSetting($prefix . 'client_id');
            $secret = PayPalProvider::getApiSetting($prefix . 'client_secret');
            $environments[$environment] = [
                'clientIdConfigured' => is_string($clientId) && trim($clientId) !== '',
                'clientSecretConfigured' => is_string($secret) && trim($secret) !== ''
            ];
        }

        return [
            'activeEnvironment' => PayPalProvider::getApiSetting('sandbox') ? 'sandbox' : 'production',
            'environments' => $environments
        ];
    }

    /** @return array<string, mixed> */
    public function testConnection(string $currency, string $country, string $environment): array
    {
        $activeSandbox = (bool)PayPalProvider::getApiSetting('sandbox');
        $sandbox = $environment === 'active' ? $activeSandbox : $environment === 'sandbox';
        $prefix = $sandbox ? 'sandbox_' : '';
        $Test = new ConnectionTest(
            (string)PayPalProvider::getApiSetting($prefix . 'client_id'),
            (string)PayPalProvider::getApiSetting($prefix . 'client_secret'),
            $sandbox
        );
        $result = $Test->run($currency, $country);
        $result['activeEnvironment'] = $activeSandbox ? 'sandbox' : 'production';

        return $result;
    }

    /** @return array<string, mixed> */
    public function log(string $file): array
    {
        return (new LogReader())->read($file);
    }

    /** @return array<string, mixed> */
    public function billingPlans(int $page, int $perPage): array
    {
        $result = BillingPlans::getBillingPlanList($page - 1, $perPage);

        if (!is_array($result)) {
            throw new RuntimeException('Billing plans are unavailable.');
        }

        return [
            'plans' => $result['plans'] ?? [],
            'count' => $result['total_items'] ?? 0,
            'page' => $page,
            'perPage' => $perPage
        ];
    }

    public function deleteBillingPlan(string $billingPlanId): void
    {
        BillingPlans::deleteBillingPlan($billingPlanId);
    }

    /** @return array<string, mixed> */
    public function billingAgreements(string $search, int $page, int $perPage): array
    {
        $parameters = [
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'sortOn' => 'paypal_agreement_id',
            'sortBy' => 'ASC'
        ];

        return [
            'agreements' => BillingAgreements::getBillingAgreementList($parameters),
            'count' => BillingAgreements::getBillingAgreementList($parameters, true),
            'page' => $page,
            'perPage' => $perPage
        ];
    }

    /** @return array<string, mixed> */
    public function billingAgreement(string $billingAgreementId): array
    {
        $local = BillingAgreements::getBillingAgreementData($billingAgreementId);

        if ($local === false) {
            throw new RuntimeException('Billing agreement is unavailable.');
        }

        return [
            'id' => $billingAgreementId,
            'local' => $local,
            'provider' => BillingAgreements::getBillingAgreementDetails($billingAgreementId)
        ];
    }

    public function cancelBillingAgreement(string $billingAgreementId, string $reason): void
    {
        if (BillingAgreements::getBillingAgreementData($billingAgreementId) === false) {
            throw new RuntimeException('Billing agreement is unavailable.');
        }

        BillingAgreements::cancelBillingAgreement($billingAgreementId, $reason);
    }

    /** @return array<string, mixed> */
    public function subscriptions(string $search, int $page, int $perPage): array
    {
        $parameters = [
            'search' => $search,
            'page' => $page,
            'perPage' => $perPage,
            'sortOn' => 'paypal_subscription_id',
            'sortBy' => 'ASC'
        ];

        return [
            'subscriptions' => Subscriptions::getSubscriptionList($parameters),
            'count' => Subscriptions::getSubscriptionList($parameters, true),
            'page' => $page,
            'perPage' => $perPage
        ];
    }

    /** @return array<string, mixed> */
    public function subscription(string $subscriptionId): array
    {
        $local = Subscriptions::getSubscriptionDataForAdministration($subscriptionId);

        if ($local === false) {
            throw new RuntimeException('Subscription is unavailable.');
        }

        $provider = null;

        try {
            $provider = Subscriptions::getSubscriptionDetails($subscriptionId, !$local['accountContextValid']);
        } catch (PayPalException $Exception) {
            if (!AccountContext::isMissingResource($Exception)) {
                throw $Exception;
            }
        }

        return [
            'id' => $subscriptionId,
            'local' => $local,
            'provider' => $provider,
            'providerAvailable' => $provider !== null,
            'accountContextValid' => $local['accountContextValid'],
            'transactions' => Subscriptions::getSubscriptionTransactionList($subscriptionId)
        ];
    }

    public function changeSubscription(string $subscriptionId, string $action, string $reason): void
    {
        if (!Subscriptions::exists($subscriptionId)) {
            throw new RuntimeException('Subscription is unavailable for the active account.');
        }

        match ($action) {
            'activate' => Subscriptions::activateSubscription($subscriptionId, $reason),
            'suspend' => Subscriptions::suspendSubscription($subscriptionId, $reason),
            'cancel' => Subscriptions::cancelSubscription($subscriptionId, $reason),
            default => throw new RuntimeException('Unsupported subscription action.')
        };
    }

    public function deleteUnassignedSubscription(string $subscriptionId): void
    {
        if (!Subscriptions::deleteUnassignedSubscription($subscriptionId)) {
            throw new RuntimeException('Subscription cannot be deleted.');
        }
    }

    public function deleteMissingSubscription(string $subscriptionId): void
    {
        (new SubscriptionCleanup())->deleteMissing($subscriptionId);
    }
}
