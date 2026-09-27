<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal;

use PaypalServerSdkLib\Exceptions\ApiException;
use QUI;
use QUI\ERP\Constants;
use QUI\ERP\Order\AbstractOrder;
use QUI\ERP\Payments\PayPal\Api\ResponseException;
use Throwable;

/** Diagnostic metadata only: never log bodies, credentials, URLs or exception traces. */
final class Diagnostics
{
    /**
     * @param array<mixed>|null $headers
     * @return array<string, mixed>
     */
    public static function responseContext(?int $status, mixed $body, ?array $headers): array
    {
        $data = is_string($body) ? json_decode($body, true) : $body;
        $context = ['httpStatus' => $status, 'responseType' => is_array($data) ? 'json' : 'non-json'];

        if (is_array($data)) {
            $context['paypalError'] = self::code($data['name'] ?? $data['error'] ?? null);
            $context['debugId'] = self::debugId($data['debug_id'] ?? null);
            $issues = [];

            foreach (is_array($data['details'] ?? null) ? $data['details'] : [] as $detail) {
                if (is_array($detail) && ($issue = self::code($detail['issue'] ?? null)) !== null) {
                    $issues[] = $issue;
                }
            }

            $context['paypalIssues'] = array_slice(array_values(array_unique($issues)), 0, 10);
        }

        foreach ($headers ?? [] as $name => $value) {
            if (is_string($name) && strtolower($name) === 'paypal-debug-id') {
                $context['debugId'] = self::debugId(is_array($value) ? reset($value) : $value);
            }
        }

        return array_filter($context, static fn ($value): bool => $value !== null && $value !== []);
    }

    /** @return array<string, mixed> */
    public static function logApiFailure(
        string $operation,
        Throwable $Error,
        ?AbstractOrder $Order = null,
        bool $pendingCaptureCheck = false
    ): array {
        $context = ['exceptionType' => get_class($Error)];

        if ($Error instanceof ResponseException) {
            $context += $Error->getDiagnostics();
        } elseif ($Error instanceof ApiException && ($Response = $Error->getHttpResponse()) !== null) {
            $context += self::responseContext(
                $Response->getStatusCode(),
                $Response->getRawBody(),
                $Response->getHeaders()
            );
        } else {
            // Legacy SDK exceptions carry their HTTP status and JSON error body on the exception itself.
            $status = $Error->getCode();
            $context += self::responseContext(
                is_int($status) && $status >= 100 && $status <= 599 ? $status : null,
                $Error->getMessage(),
                []
            );
        }

        if ($Order !== null) {
            $context['orderId'] = $Order->getId();
            $context['orderUuid'] = self::identifier($Order->getUUID());
            $context['paypalOrderId'] = self::identifier($Order->getPaymentDataEntry(Payment::ATTR_PAYPAL_ORDER_ID));
            $paidStatus = $Order->getAttribute('paid_status');
            $statuses = [
                Constants::PAYMENT_STATUS_OPEN => 'open',
                Constants::PAYMENT_STATUS_PAID => 'paid',
                Constants::PAYMENT_STATUS_PART => 'partially_paid',
                Constants::PAYMENT_STATUS_ERROR => 'error',
                Constants::PAYMENT_STATUS_CANCELED => 'canceled',
                Constants::PAYMENT_STATUS_DEBIT => 'debit',
                Constants::PAYMENT_STATUS_PLAN => 'plan'
            ];

            if (
                (is_int($paidStatus) || (is_string($paidStatus) && ctype_digit($paidStatus)))
                && isset($statuses[(int)$paidStatus])
            ) {
                $context['paidStatus'] = (int)$paidStatus;
                $context['paidStatusName'] = $statuses[(int)$paidStatus];
            }
        }

        if ($pendingCaptureCheck) {
            $context['source'] = 'cron';
            $context['cronJob'] = 'checkPendingCaptures';
        }

        self::write('PayPal API operation failed.', $operation, $context);
        return $context;
    }

    /** @return array{clientId: bool|string, sandbox: bool, diagnosticsToken: string} */
    public static function getSdkConfig(): array
    {
        $sandbox = (bool)Provider::getApiSetting('sandbox');
        $token = QUI::getSession()->get('paypalDiagnosticsToken');

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            QUI::getSession()->set('paypalDiagnosticsToken', $token);
        }

        $clientId = Provider::getApiSetting($sandbox ? 'sandbox_client_id' : 'client_id');

        if (!is_string($clientId) || trim($clientId) === '') {
            self::write('PayPal client ID is missing.', 'sdkConfiguration', []);
        }

        return ['clientId' => $clientId, 'sandbox' => $sandbox, 'diagnosticsToken' => $token];
    }

    /** @param array<string, mixed> $context Sanitized diagnostic metadata, never raw API responses. */
    public static function logConnectionTestFailure(
        string $operation,
        array $context,
        string $testId,
        bool $sandbox
    ): void {
        self::write('PayPal administration connection test failed.', $operation, [
            'source' => 'admin_connection_test',
            'testId' => $testId,
            'testedEnvironment' => $sandbox ? 'sandbox' : 'production'
        ] + $context);
    }

    /** Accept a bounded, session-authenticated browser report, including guest checkouts. */
    public static function logBrowserError(string $token, string $payload): bool
    {
        $Session = QUI::getSession();
        $expected = $Session->get('paypalDiagnosticsToken');

        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $token) || strlen($payload) > 4096) {
            return false;
        }

        $data = json_decode($payload, true);
        $operations = ['loadSdk', 'createInstance', 'findEligibleMethods', 'createSession', 'startSession',
            'createOrder', 'executeOrder', 'sdkConfiguration'];

        if (!is_array($data) || !in_array($data['operation'] ?? null, $operations, true)) {
            return false;
        }

        // A broken widget must not flood the log. Configuration reloads do not reset this limit.
        $rate = $Session->get('paypalDiagnosticsRate');

        if (!is_array($rate) || ($rate['since'] ?? 0) < time() - 60) {
            $rate = ['since' => time(), 'count' => 0];
        }

        if (($rate['count'] ?? 0) >= 10) {
            return false;
        }

        $rate['count']++;
        $Session->set('paypalDiagnosticsRate', $rate);
        $context = ['source' => 'browser'];
        $reasons = ['missing_auth', 'missing_client_id', 'environment_mismatch', 'sdk_load_failed',
            'not_eligible', 'operation_failed'];
        $context['reason'] = in_array($data['reason'] ?? null, $reasons, true)
            ? $data['reason'] : 'operation_failed';

        foreach (['reportedEnvironment', 'sdkEnvironment'] as $key) {
            if (in_array($data[$key] ?? null, ['sandbox', 'production', 'unknown'], true)) {
                $context[$key] = $data[$key];
            }
        }

        if (is_int($data['httpStatus'] ?? null) && $data['httpStatus'] >= 100 && $data['httpStatus'] <= 599) {
            $context['httpStatus'] = $data['httpStatus'];
        }

        $errorNames = ['Error', 'TypeError', 'SdkInitError', 'SdkError', 'DevError', 'PaymentFlowError'];

        if (in_array($data['errorName'] ?? null, $errorNames, true)) {
            $context['errorName'] = $data['errorName'];
        }

        $sdkCode = self::code($data['sdkErrorCode'] ?? null);
        $context['sdkErrorCode'] = $sdkCode !== null && str_starts_with($sdkCode, 'ERR_') ? $sdkCode : null;
        $context['paypalError'] = self::code($data['paypalError'] ?? null);
        $issues = [];

        foreach (is_array($data['paypalIssues'] ?? null) ? array_slice($data['paypalIssues'], 0, 10) : [] as $issue) {
            if (($code = self::code($issue)) !== null) {
                $issues[] = $code;
            }
        }

        if ($issues !== []) {
            $context['paypalIssues'] = array_values(array_unique($issues));
        }

        $context['debugId'] = self::debugId($data['debugId'] ?? null);
        if (
            $data['operation'] === 'findEligibleMethods'
            && $context['reason'] === 'operation_failed'
            && $context['paypalError'] === null
            && $issues === []
            && !isset($context['httpStatus'])
            && $context['debugId'] === null
        ) {
            $context['diagnosticHint'] = 'The SDK did not expose the underlying PayPal API error. '
                . 'Inspect find-eligible-methods in browser Network: HTTP status, name, details[].issue and debug_id.';
        }

        $context['correlationId'] = is_string($data['correlationId'] ?? null)
            && preg_match('/\A[0-9a-f-]{36}\z/D', $data['correlationId']) === 1 ? $data['correlationId'] : null;
        self::write('PayPal browser operation failed.', $data['operation'], $context);
        return true;
    }

    /** @param array<string, mixed> $context */
    private static function write(string $message, string $operation, array $context): void
    {
        QUI\System\Log::write($message, QUI\System\Log::LEVEL_ERROR, array_filter([
            'paypalOperation' => $operation,
            'environment' => Provider::getApiSetting('sandbox') ? 'sandbox' : 'production',
            'occurredAt' => gmdate('c')
        ] + $context, static fn ($value): bool => $value !== null), 'paypal_api');
    }

    private static function code(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z][A-Za-z0-9_]{1,79}\z/D', $value) === 1 ? $value : null;
    }

    private static function identifier(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $value) === 1 ? $value : null;
    }

    private static function debugId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9a-fA-F]{8,32}\z/D', $value) === 1 ? $value : null;
    }
}
