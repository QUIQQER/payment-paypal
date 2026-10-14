<?php

declare(strict_types=1);

namespace QUI\ERP\Payments\PayPal\MCP;

use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use QUI;
use QUI\AI\MCP\ProviderInterface;
use QUI\AI\MCP\Server;
use QUI\AI\MCP\ToolHelper;
use QUI\Permissions\Permission;
use Throwable;

/** Optional MCP administration; checkout approval remains in the customer payment flow. */
final class Provider implements ProviderInterface
{
    public const PERMISSION = 'quiqqer.payments.paypal.mcp';
    private const DOMAIN_PREFIX = 'quiqqer.payments.paypal.';

    public function __construct(private readonly Operations $Operations = new Operations())
    {
    }

    public function register(Builder $serverBuilder): void
    {
        try {
            Permission::checkPermission(self::PERMISSION, Server::getRequestUser());
        } catch (Throwable) {
            return;
        }

        $this->registerDiagnostics($serverBuilder);
        $this->registerBillingPlans($serverBuilder);
        $this->registerBillingAgreements($serverBuilder);
        $this->registerSubscriptions($serverBuilder);
    }

    private function registerDiagnostics(Builder $Builder): void
    {
        $Builder->addTool(
            fn(): array|CallToolResult => $this->run(
                'quiqqer.settings',
                fn(): array => $this->Operations->configuration()
            ),
            name: 'paypal_configuration_get',
            description: 'Reads the active PayPal environment and whether credentials are configured. '
                . 'Returns no credential values. Requires the settings permission.',
            inputSchema: $this->schema([])
        );

        $Builder->addTool(
            fn(
                mixed $currency = 'EUR',
                mixed $country = 'DE',
                mixed $environment = 'active'
            ): array|CallToolResult => $this->run(
                'quiqqer.settings',
                fn(): array => $this->Operations->testConnection(
                    Input::pattern($currency, 'currency', '/\A[A-Z]{3}\z/D'),
                    Input::pattern($country, 'country', '/\A[A-Z]{2}\z/D'),
                    Input::choice($environment, 'environment', ['active', 'production', 'sandbox'])
                )
            ),
            name: 'paypal_connection_test',
            description: 'Tests stored PayPal credentials and eligible payment methods with sanitized diagnostics. '
                . 'Does not create or capture payments or change the configured environment. '
                . 'Requires the settings permission.',
            inputSchema: $this->schema([
                'currency' => [
                    'type' => 'string',
                    'pattern' => '^[A-Z]{3}$',
                    'default' => 'EUR'
                ],
                'country' => [
                    'type' => 'string',
                    'pattern' => '^[A-Z]{2}$',
                    'default' => 'DE'
                ],
                'environment' => [
                    'type' => 'string',
                    'enum' => ['active', 'production', 'sandbox'],
                    'default' => 'active'
                ]
            ])
        );

        $Builder->addTool(
            fn(mixed $file = ''): array|CallToolResult => $this->run(
                'superuser',
                fn(): array => $this->Operations->log(
                    Input::pattern($file, 'file', '/\A(?:paypal_api-\d{4}-\d{2}-\d{2}\.log)?\z/D')
                )
            ),
            name: 'paypal_log_get',
            description: 'Reads a bounded tail of a PayPal API diagnostic log and lists available log filenames. '
                . 'An empty filename selects the newest log. Requires a superuser.',
            inputSchema: $this->schema([
                'file' => [
                    'type' => 'string',
                    'pattern' => '^(paypal_api-[0-9]{4}-[0-9]{2}-[0-9]{2}\\.log)?$',
                    'default' => ''
                ]
            ])
        );
    }

    private function registerBillingPlans(Builder $Builder): void
    {
        $Builder->addTool(
            fn(mixed $page = 1, mixed $perPage = 10): array|CallToolResult => $this->run(
                self::DOMAIN_PREFIX . 'billing_plans.view',
                fn(): array => $this->Operations->billingPlans(
                    Input::integer($page, 'page', 1, 10000),
                    Input::integer($perPage, 'perPage', 1, 20)
                )
            ),
            name: 'paypal_billing_plans_list',
            description: 'Lists active legacy Billing Plans from PayPal. Page numbers start at 1. '
                . 'These are legacy plans, not modern Subscription API plans.',
            inputSchema: $this->schema($this->pagination(20))
        );

        $Builder->addTool(
            function (mixed $billingPlanId = null): array|CallToolResult {
                return $this->run(
                    self::DOMAIN_PREFIX . 'billing_plans.delete',
                    function () use ($billingPlanId): array {
                        $id = Input::pattern($billingPlanId, 'billingPlanId', '/\AP-[A-Z0-9]{1,126}\z/D');
                        $this->Operations->deleteBillingPlan($id);

                        return [
                            'id' => $id,
                            'deleted' => true
                        ];
                    }
                );
            },
            name: 'paypal_billing_plan_delete',
            description: 'Deletes a legacy Billing Plan at PayPal. Requires the Billing Plan deletion permission.',
            inputSchema: $this->schema(['billingPlanId' => $this->identifier('P')], ['billingPlanId'])
        );
    }

    private function registerBillingAgreements(Builder $Builder): void
    {
        $Builder->addTool(
            fn(
                mixed $search = '',
                mixed $page = 1,
                mixed $perPage = 10
            ): array|CallToolResult => $this->run(
                self::DOMAIN_PREFIX . 'billing_agreements.view',
                fn(): array => $this->Operations->billingAgreements(
                    Input::text($search, 'search', 200),
                    Input::integer($page, 'page', 1, 10000),
                    Input::integer($perPage, 'perPage', 1, 100)
                )
            ),
            name: 'paypal_billing_agreements_list',
            description: 'Lists locally stored legacy Billing Agreements. Search matches the global process ID; '
                . 'page numbers start at 1.',
            inputSchema: $this->schema($this->searchPagination())
        );

        $Builder->addTool(
            fn(mixed $billingAgreementId = null): array|CallToolResult => $this->run(
                self::DOMAIN_PREFIX . 'billing_agreements.view',
                fn(): array => $this->Operations->billingAgreement(
                    Input::pattern($billingAgreementId, 'billingAgreementId', '/\AI-[A-Z0-9]{1,126}\z/D')
                )
            ),
            name: 'paypal_billing_agreement_get',
            description: 'Reads a locally stored legacy Billing Agreement and its current PayPal details.',
            inputSchema: $this->schema(['billingAgreementId' => $this->identifier('I')], ['billingAgreementId'])
        );

        $Builder->addTool(
            function (
                mixed $billingAgreementId = null,
                mixed $reason = 'Cancelled via QUIQQER MCP'
            ): array|CallToolResult {
                return $this->run(
                    self::DOMAIN_PREFIX . 'billing_agreements.cancel',
                    function () use ($billingAgreementId, $reason): array {
                        $id = Input::pattern($billingAgreementId, 'billingAgreementId', '/\AI-[A-Z0-9]{1,126}\z/D');
                        $note = Input::text($reason, 'reason', 128, false);
                        $this->Operations->cancelBillingAgreement($id, $note);

                        return [
                            'id' => $id,
                            'cancelled' => true
                        ];
                    }
                );
            },
            name: 'paypal_billing_agreement_cancel',
            description: 'Permanently cancels a locally stored legacy Billing Agreement at PayPal and marks it inactive. '
                . 'Requires the Billing Agreement cancellation permission.',
            inputSchema: $this->schema([
                'billingAgreementId' => $this->identifier('I'),
                'reason' => $this->reason('Cancelled via QUIQQER MCP')
            ], ['billingAgreementId'])
        );
    }

    private function registerSubscriptions(Builder $Builder): void
    {
        $Builder->addTool(
            fn(
                mixed $search = '',
                mixed $page = 1,
                mixed $perPage = 10
            ): array|CallToolResult => $this->run(
                self::DOMAIN_PREFIX . 'subscriptions.view',
                fn(): array => $this->Operations->subscriptions(
                    Input::text($search, 'search', 200),
                    Input::integer($page, 'page', 1, 10000),
                    Input::integer($perPage, 'perPage', 1, 100)
                )
            ),
            name: 'paypal_subscriptions_list',
            description: 'Lists local PayPal Subscriptions for the active account, including unassigned records. '
                . 'Search matches subscription, plan, customer or global process ID; page numbers start at 1.',
            inputSchema: $this->schema($this->searchPagination())
        );

        $Builder->addTool(
            fn(mixed $subscriptionId = null): array|CallToolResult => $this->run(
                self::DOMAIN_PREFIX . 'subscriptions.view',
                fn(): array => $this->Operations->subscription(Input::subscriptionId($subscriptionId))
            ),
            name: 'paypal_subscription_get',
            description: 'Reads local and PayPal details of a Subscription for the active account, '
                . 'its account assignment and up to 50 recent transactions.',
            inputSchema: $this->schema(['subscriptionId' => $this->identifier('I')], ['subscriptionId'])
        );

        $descriptions = [
            'activate' => 'Activates a suspended PayPal Subscription for the active account and resumes payments.',
            'suspend' => 'Temporarily suspends automated payments for a PayPal Subscription of the active account.',
            'cancel' => 'Permanently cancels a PayPal Subscription for the active account and marks it inactive.'
        ];

        foreach ($descriptions as $action => $description) {
            $Builder->addTool(
                function (
                    mixed $subscriptionId = null,
                    mixed $reason = 'Changed via QUIQQER MCP'
                ) use ($action): array|CallToolResult {
                    return $this->run(
                        self::DOMAIN_PREFIX . 'subscriptions.manage',
                        function () use ($subscriptionId, $reason, $action): array {
                            $id = Input::subscriptionId($subscriptionId);
                            $note = Input::text($reason, 'reason', 128, false);
                            $this->Operations->changeSubscription($id, $action, $note);

                            return [
                                'id' => $id,
                                'action' => $action,
                                'success' => true
                            ];
                        }
                    );
                },
                name: 'paypal_subscription_' . $action,
                description: $description . ' Requires the Subscription management permission.',
                inputSchema: $this->schema([
                    'subscriptionId' => $this->identifier('I'),
                    'reason' => $this->reason('Changed via QUIQQER MCP')
                ], ['subscriptionId'])
            );
        }

        $cleanupDescriptions = [
            'unassigned' => 'Deletes an unassigned local Subscription and its auxiliary records. '
                . 'Records assigned to a PayPal account are protected.',
            'missing' => 'Deletes an abandoned, approval-pending local Subscription only after PayPal confirms '
                . 'RESOURCE_NOT_FOUND. Contracts, transactions and approved subscriptions are protected.'
        ];

        foreach ($cleanupDescriptions as $mode => $description) {
            $Builder->addTool(
                function (mixed $subscriptionId = null) use ($mode): array|CallToolResult {
                    return $this->run(
                        self::DOMAIN_PREFIX . 'subscriptions.manage',
                        function () use ($subscriptionId, $mode): array {
                            $id = Input::subscriptionId($subscriptionId);

                            if ($mode === 'unassigned') {
                                $this->Operations->deleteUnassignedSubscription($id);
                            } else {
                                $this->Operations->deleteMissingSubscription($id);
                            }

                            return [
                                'id' => $id,
                                'deleted' => true
                            ];
                        }
                    );
                },
                name: 'paypal_subscription_delete_' . $mode,
                description: $description . ' Requires the Subscription management permission.',
                inputSchema: $this->schema(['subscriptionId' => $this->identifier('I')], ['subscriptionId'])
            );
        }
    }

    /**
     * @param callable(): array<string, mixed> $Operation
     * @return array<string, mixed>|CallToolResult
     */
    private function run(string $permission, callable $Operation): array|CallToolResult
    {
        try {
            $User = Server::getRequestUser();
            Permission::checkPermission(self::PERMISSION, $User);

            if ($permission === 'superuser') {
                Permission::checkSU($User);
            } else {
                Permission::checkPermission($permission, $User);
            }
        } catch (Throwable $Exception) {
            return ToolHelper::parseExceptionToResult($Exception);
        }

        try {
            return $Operation();
        } catch (InvalidInputException $Exception) {
            return ToolHelper::parseExceptionToResult(new QUI\Exception([
                'quiqqer/payment-paypal',
                'exception.mcp.invalid_parameter',
                ['parameter' => $Exception->getMessage()]
            ], 400));
        } catch (Throwable) {
            // SDK/DB exceptions can contain credentials, response bodies or internal paths.
            return ToolHelper::parseExceptionToResult(new QUI\Exception([
                'quiqqer/payment-paypal',
                'exception.mcp.operation_failed'
            ], 500));
        }
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private function schema(array $properties, array $required = []): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $properties === [] ? new \stdClass() : $properties,
            'required' => $required
        ];
    }

    /** @return array<string, mixed> */
    private function pagination(int $maximum): array
    {
        return [
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 10000,
                'default' => 1
            ],
            'perPage' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => $maximum,
                'default' => 10
            ]
        ];
    }

    /** @return array<string, mixed> */
    private function searchPagination(): array
    {
        return [
            'search' => [
                'type' => 'string',
                'maxLength' => 200,
                'default' => ''
            ]
        ] + $this->pagination(100);
    }

    /** @return array<string, mixed> */
    private function identifier(string $prefix): array
    {
        return [
            'type' => 'string',
            'pattern' => '^' . $prefix . '-[A-Z0-9]{1,126}$',
            'minLength' => 3,
            'maxLength' => 128,
            'description' => 'PayPal resource ID, including the ' . $prefix . '- prefix.'
        ];
    }

    /** @return array<string, mixed> */
    private function reason(string $default): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 128,
            'default' => $default
        ];
    }
}
