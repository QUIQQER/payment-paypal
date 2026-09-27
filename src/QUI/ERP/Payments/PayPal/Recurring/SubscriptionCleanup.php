<?php

namespace QUI\ERP\Payments\PayPal\Recurring;

use Doctrine\DBAL\Connection;
use QUI;
use QUI\ERP\Accounting\Contracts\Handler as Contracts;
use QUI\ERP\Payments\PayPal\AccountContext;
use QUI\ERP\Payments\PayPal\PayPalException;
use QUI\ERP\Payments\PayPal\Recurring\Subscriptions\RequestException;
use QUI\Utils\Doctrine;

/**
 * Explicit administrative cleanup of abandoned, never approved subscriptions.
 */
class SubscriptionCleanup
{
    public function deleteMissing(string $subscriptionId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $subscriptionId)) {
            $this->reject('protected');
        }

        $Connection = QUI::getDataBaseConnection();
        $accountHash = AccountContext::getHash();
        $table = QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTIONS);
        $row = $Connection->fetchAssociative(
            'SELECT * FROM ' . Doctrine::quoteIdentifier($table)
            . ' WHERE paypal_subscription_id = ? AND paypal_account_hash = ?',
            [$subscriptionId, $accountHash]
        );

        if ($row === false) {
            $this->reject('protected');
        }

        $data = json_decode($row['subscription_data'] ?? '', true);

        if (($data['status'] ?? '') !== Subscriptions::STATUS_APPROVAL_PENDING) {
            $this->reject('protected');
        }

        $this->assertNoReferences($subscriptionId, $row['global_process_id'] ?? '');

        try {
            Subscriptions::getSubscriptionDetails($subscriptionId, true);
            $this->reject('exists');
        } catch (RequestException $Exception) {
            if ($Exception->getCode() !== 404 || $Exception->responseName !== 'RESOURCE_NOT_FOUND') {
                throw $Exception;
            }
        }

        $Connection->transactional(function (Connection $Connection) use (
            $subscriptionId,
            $accountHash,
            $row,
            $table
        ): void {
            // Recheck local references after the network request. Only delete
            // the exact record inspected above; concurrent status changes win.
            $this->assertNoReferences($subscriptionId, $row['global_process_id'] ?? '');
            $deleted = $Connection->delete($table, [
                'paypal_subscription_id' => $subscriptionId,
                'paypal_account_hash' => $accountHash,
                'subscription_data' => $row['subscription_data'],
                'global_process_id' => $row['global_process_id'],
                'active' => $row['active']
            ]);

            if ($deleted !== 1) {
                $this->reject('protected');
            }

            $Connection->delete(
                QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_WEBHOOK_EVENTS),
                ['paypal_subscription_id' => $subscriptionId]
            );
        });
    }

    protected function assertNoReferences(string $subscriptionId, string $processId): void
    {
        $hasTransactions = QUI::getDataBaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM '
            . Doctrine::quoteIdentifier(QUI::getDBTableName(Subscriptions::TBL_SUBSCRIPTION_TRANSACTIONS))
            . ' WHERE paypal_subscription_id = ?',
            [$subscriptionId]
        );

        if ($hasTransactions || $this->hasContract($processId)) {
            $this->reject('linked');
        }
    }

    protected function hasContract(string $processId): bool
    {
        if ($processId === '' || !class_exists(Contracts::class)) {
            return false;
        }

        // The Handler lookup catches database errors and returns null. For a
        // destructive action query directly so lookup errors prevent deletion.
        return (bool)QUI::getDataBaseConnection()->fetchOne(
            'SELECT COUNT(*) FROM ' . Doctrine::quoteIdentifier(QUI::getDBTableName(Contracts::TABLE_CONTRACTS))
            . ' WHERE global_process_id = ?',
            [$processId]
        );
    }

    protected function reject(string $reason): never
    {
        throw new PayPalException(QUI::getLocale()->get(
            'quiqqer/payment-paypal',
            'exception.subscription.cleanup.' . $reason
        ));
    }
}
