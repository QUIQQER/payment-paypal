<?php

declare(strict_types=1);

namespace QUITests\ERP\Payments\PayPal\Unit;

use InvalidArgumentException;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Server\Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use QUI;
use QUI\AI\MCP\Server;
use QUI\ERP\Payments\PayPal\MCP\Operations;
use QUI\ERP\Payments\PayPal\MCP\Provider;
use QUI\Interfaces\Users\User;
use QUI\Permissions\Manager;
use ReflectionProperty;
use RuntimeException;

final class McpProviderTest extends TestCase
{
    private mixed $originalRequestUser;
    private ?Manager $originalRights;
    private Operations & MockObject $Operations;

    protected function setUp(): void
    {
        $this->originalRequestUser = (new ReflectionProperty(Server::class, 'RequestUser'))->getValue();
        $this->originalRights = QUI::$Rights;
        $this->setRequestUser(QUI::getUsers()->getSystemUser());
        $this->Operations = $this->createMock(Operations::class);
    }

    protected function tearDown(): void
    {
        $this->setRequestUser($this->originalRequestUser);
        QUI::$Rights = $this->originalRights;
    }

    public function testRegistrationAndRevocationForEveryTool(): void
    {
        $tools = $this->tools();
        self::assertSame([
            'paypal_configuration_get',
            'paypal_connection_test',
            'paypal_log_get',
            'paypal_billing_plans_list',
            'paypal_billing_plan_delete',
            'paypal_billing_agreements_list',
            'paypal_billing_agreement_get',
            'paypal_billing_agreement_cancel',
            'paypal_subscriptions_list',
            'paypal_subscription_get',
            'paypal_subscription_activate',
            'paypal_subscription_suspend',
            'paypal_subscription_cancel',
            'paypal_subscription_delete_unassigned',
            'paypal_subscription_delete_missing'
        ], array_keys($tools));
        $this->expectNoOperation();
        $this->setRestrictedUser([]);
        self::assertSame([], $this->tools());

        foreach ($tools as $tool) {
            self::assertFalse($tool['inputSchema']['additionalProperties']);
            self::assertNotEmpty($tool['description']);
            $this->assertError($tool['handler']());
        }
    }

    public function testEveryToolRequiresItsDomainPermission(): void
    {
        $this->setRestrictedUser([Provider::PERMISSION => true]);
        $this->expectNoOperation();

        foreach ($this->tools() as $name => $tool) {
            $arguments = match ($name) {
                'paypal_billing_plan_delete' => ['P-TEST1'],
                'paypal_billing_agreement_get', 'paypal_billing_agreement_cancel' => ['I-TEST1'],
                default => str_starts_with($name, 'paypal_subscription_') ? ['I-TEST1'] : []
            };
            $this->assertError($tool['handler'](...$arguments));
        }
    }

    public function testLogAccessRequiresSuperuserEvenWithSettingsPermission(): void
    {
        $this->setRestrictedUser([Provider::PERMISSION => true, 'quiqqer.settings' => true]);
        $this->Operations->expects(self::never())->method('log');
        $this->assertError($this->tools()['paypal_log_get']['handler']());
    }

    #[DataProvider('invalidArguments')]
    public function testInvalidArgumentsNeverReachTheDomain(string $name, array $arguments): void
    {
        $this->expectNoOperation();
        $this->assertError($this->tools()[$name]['handler'](...$arguments));
    }

    public static function invalidArguments(): iterable
    {
        foreach ([null, false, 1, [], new \stdClass(), '', ' ', 'I-TEST1/../cancel', "I-TEST1\n", 'P-TEST1'] as $id) {
            yield ['paypal_subscription_get', [$id]];
            yield ['paypal_subscription_cancel', [$id]];
            yield ['paypal_billing_agreement_get', [$id]];
            yield ['paypal_subscription_delete_missing', [$id]];
        }

        foreach ([null, false, '1', 1.0, [], 0, -1, 10001, PHP_INT_MAX] as $page) {
            yield ['paypal_subscriptions_list', ['', $page]];
            yield ['paypal_billing_plans_list', [$page]];
        }

        foreach ([null, false, '10', 1.5, [], 0, -1, 101] as $limit) {
            yield ['paypal_subscriptions_list', ['', 1, $limit]];
        }

        foreach ([null, false, [], '', ' ', "bad\nreason", str_repeat('x', 129), "\xff"] as $reason) {
            yield ['paypal_subscription_suspend', ['I-TEST1', $reason]];
            yield ['paypal_billing_agreement_cancel', ['I-TEST1', $reason]];
        }

        yield ['paypal_subscription_get', []];
        yield ['paypal_billing_plan_delete', []];
        yield ['paypal_billing_plan_delete', ['I-TEST1']];
        yield ['paypal_billing_plans_list', [1, 21]];
        yield ['paypal_subscriptions_list', [str_repeat('x', 201)]];
        yield ['paypal_billing_agreements_list', [[]]];
        yield ['paypal_connection_test', [[], 'DE']];
        yield ['paypal_connection_test', ['eur', 'DE']];
        yield ['paypal_connection_test', ['EUR', "DE\n"]];
        yield ['paypal_connection_test', ['EUR', 'DE', true]];
        yield ['paypal_connection_test', ['EUR', 'DE', 'https://evil.example']];
        yield ['paypal_log_get', ['../../etc/passwd']];
        yield ['paypal_log_get', [false]];
        yield ['paypal_log_get', ["paypal_api-2026-10-14.log\n"]];
    }

    public function testRestrictedReaderCanReadButCannotWrite(): void
    {
        $this->setRestrictedUser([
            Provider::PERMISSION => true,
            'quiqqer.payments.paypal.subscriptions.view' => true
        ]);
        $this->Operations->expects(self::once())->method('subscriptions')
            ->with('TEST', 2, 25)->willReturn(['subscriptions' => [], 'count' => 0]);
        $this->Operations->expects(self::never())->method('changeSubscription');
        $tools = $this->tools();
        self::assertSame(
            ['subscriptions' => [], 'count' => 0],
            $tools['paypal_subscriptions_list']['handler']('TEST', 2, 25)
        );
        $this->assertError($tools['paypal_subscription_cancel']['handler']('I-TEST1'));
    }

    public function testAllSubscriptionActionsDispatchWithValidatedArguments(): void
    {
        $this->setRestrictedUser([
            Provider::PERMISSION => true,
            'quiqqer.payments.paypal.subscriptions.manage' => true
        ]);
        $calls = [];
        $this->Operations->expects(self::exactly(3))->method('changeSubscription')
            ->willReturnCallback(static function (string $id, string $action, string $reason) use (&$calls): void {
                $calls[] = [$id, $action, $reason];
            });
        $tools = $this->tools();

        foreach (['activate', 'suspend', 'cancel'] as $action) {
            self::assertSame(
                ['id' => 'I-TEST1', 'action' => $action, 'success' => true],
                $tools['paypal_subscription_' . $action]['handler']('I-TEST1', 'Reviewed request')
            );
        }

        self::assertSame([
            ['I-TEST1', 'activate', 'Reviewed request'],
            ['I-TEST1', 'suspend', 'Reviewed request'],
            ['I-TEST1', 'cancel', 'Reviewed request']
        ], $calls);
    }

    public function testRemainingToolsDispatchWithDefaultsAndNamedParameters(): void
    {
        $User = $this->createMock(QUI\Users\User::class);
        $User->method('isSU')->willReturn(true);
        $this->setRequestUser($User);
        $this->Operations->expects(self::once())->method('configuration')->willReturn(['configured' => true]);
        $this->Operations->expects(self::once())->method('testConnection')
            ->with('USD', 'US', 'sandbox')->willReturn(['environment' => 'sandbox']);
        $this->Operations->expects(self::once())->method('log')->with('')->willReturn(['data' => '']);
        $this->Operations->expects(self::once())->method('billingPlans')->with(1, 10)->willReturn(['plans' => []]);
        $this->Operations->expects(self::once())->method('deleteBillingPlan')->with('P-TEST1');
        $this->Operations->expects(self::once())->method('billingAgreements')
            ->with('', 1, 10)->willReturn(['agreements' => []]);
        $this->Operations->expects(self::once())->method('billingAgreement')
            ->with('I-TEST1')->willReturn(['id' => 'I-TEST1']);
        $this->Operations->expects(self::once())->method('cancelBillingAgreement')
            ->with('I-TEST1', 'Cancelled via QUIQQER MCP');
        $this->Operations->expects(self::once())->method('subscription')
            ->with('I-TEST1')->willReturn(['id' => 'I-TEST1']);
        $this->Operations->expects(self::once())->method('deleteUnassignedSubscription')->with('I-TEST1');
        $this->Operations->expects(self::once())->method('deleteMissingSubscription')->with('I-TEST1');
        $tools = $this->tools();
        $arguments = [
            'paypal_configuration_get' => [],
            'paypal_connection_test' => ['environment' => 'sandbox', 'currency' => 'USD', 'country' => 'US'],
            'paypal_log_get' => [],
            'paypal_billing_plans_list' => [],
            'paypal_billing_plan_delete' => ['billingPlanId' => 'P-TEST1'],
            'paypal_billing_agreements_list' => [],
            'paypal_billing_agreement_get' => ['billingAgreementId' => 'I-TEST1'],
            'paypal_billing_agreement_cancel' => ['billingAgreementId' => 'I-TEST1'],
            'paypal_subscription_get' => ['subscriptionId' => 'I-TEST1'],
            'paypal_subscription_delete_unassigned' => ['subscriptionId' => 'I-TEST1'],
            'paypal_subscription_delete_missing' => ['subscriptionId' => 'I-TEST1']
        ];

        foreach ($arguments as $name => $values) {
            self::assertIsArray($tools[$name]['handler'](...$values));
        }
    }

    #[DataProvider('unsafeExceptions')]
    public function testInternalFailureMessagesAreNotExposed(\Throwable $Exception): void
    {
        $this->Operations->method('subscription')->willThrowException($Exception);
        $result = $this->tools()['paypal_subscription_get']['handler']('I-TEST1');
        $this->assertError($result);
        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-client-secret', $encoded);
        self::assertStringNotContainsString('/etc/private-path', $encoded);
    }

    public static function unsafeExceptions(): iterable
    {
        $message = 'private-client-secret /etc/private-path';
        yield [new RuntimeException($message)];
        yield [new InvalidArgumentException($message)];
        yield [new QUI\Exception($message)];
    }

    public function testProviderPermissionAndLocalesAreDeclaredOnce(): void
    {
        $directory = dirname(__DIR__, 2);
        $Package = simplexml_load_file($directory . '/package.xml');
        self::assertSame(
            '\\' . Provider::class,
            (string)$Package->package->provider->mcp['src']
        );
        $Permissions = simplexml_load_file($directory . '/permissions.xml');
        $matches = $Permissions->xpath('//permission[@name="' . Provider::PERMISSION . '"]');
        self::assertCount(1, $matches);
        self::assertSame('0', (string)$matches[0]->defaultvalue);
        self::assertSame('1', (string)$matches[0]->rootPermission);

        $keys = [
            'permission.' . Provider::PERMISSION,
            'exception.mcp.invalid_parameter',
            'exception.mcp.operation_failed'
        ];

        foreach (LocaleFiles::load() as $Document) {
            foreach ($keys as $key) {
                self::assertCount(1, $Document->xpath('//locale[@name="' . $key . '"]'));
            }
        }
    }

    private function assertError(mixed $result): void
    {
        self::assertInstanceOf(CallToolResult::class, $result);
        self::assertTrue($result->isError);
    }

    private function expectNoOperation(): void
    {
        foreach ((new \ReflectionClass(Operations::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $Method) {
            $this->Operations->expects(self::never())->method($Method->getName());
        }
    }

    private function tools(): array
    {
        $Builder = new Builder();
        (new Provider($this->Operations))->register($Builder);
        $tools = (new ReflectionProperty(Builder::class, 'tools'))->getValue($Builder);

        return array_column($tools, null, 'name');
    }

    private function setRequestUser(?User $User): void
    {
        (new ReflectionProperty(Server::class, 'RequestUser'))->setValue(null, $User);
    }

    private function setRestrictedUser(array $permissions): void
    {
        $User = $this->createMock(User::class);
        $User->method('getUUID')->willReturn('paypal-mcp-test-user');
        $User->method('getGroups')->willReturn([]);
        $Rights = $this->createMock(Manager::class);
        $Rights->method('getPermissions')->willReturn($permissions);
        QUI::$Rights = $Rights;
        $this->setRequestUser($User);
    }
}
