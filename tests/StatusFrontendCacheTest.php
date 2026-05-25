<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';
require_once __DIR__ . '/../AliyunTrafficCheck.php';

class FakeContextProvider implements CloudProviderInterface
{
    public $describeCalls = 0;

    public function getProviderKey(): string
    {
        return 'fake-context';
    }

    public function getCapabilities(array $account): array
    {
        return [
            'traffic_monitor' => true,
            'security_group_manage' => true,
            'instance_start_stop' => true,
            'stop_charging' => false,
            'billing_summary' => false,
            'schedule_manage' => true,
            'per_account_proxy' => true,
            'site_type_select' => false,
            'region_picker' => true,
        ];
    }

    public function describeAccountContext(array $account): array
    {
        $this->describeCalls++;

        return [
            'instanceId' => $account['instance_id'],
            'instanceName' => 'fresh-from-api',
            'instanceStatus' => 'Stopped',
            'publicIp' => '203.0.113.10',
            'securityGroups' => [],
            'securityGroupCount' => 0,
            'securityGroupNames' => [],
            'discoveryStatus' => 'success',
            'discoveryMode' => 'instance',
            'discoveryMessage' => '',
            'usingFallbackSecurityGroup' => false,
            'fallbackSecurityGroupId' => '',
            'trafficDataAvailable' => true,
            'trafficUsedGb' => 99.0,
            'trafficError' => '',
        ];
    }

    public function getTraffic(array $account)
    {
        return 0;
    }

    public function getInstanceStatus(array $account)
    {
        return 'Unknown';
    }

    public function controlInstance(array $account, string $action, string $shutdownMode = 'KeepCharging')
    {
        return true;
    }

    public function getAccountBalance(array $account)
    {
        return null;
    }

    public function getInstanceBill(array $account, string $billingCycle)
    {
        return null;
    }

    public function getBillOverview(array $account, string $billingCycle)
    {
        return null;
    }

    public function getInstanceSecurityGroups(array $account)
    {
        return [];
    }

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        return true;
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        return true;
    }
}

class FakeProviderFactory
{
    private $provider;

    public function __construct($provider)
    {
        $this->provider = $provider;
    }

    public function getProvider(string $providerKey): CloudProviderInterface
    {
        return $this->provider;
    }
}

function setPrivateProperty($object, string $name, $value): void
{
    $property = new ReflectionProperty($object, $name);
    $property->setAccessible(true);
    $property->setValue($object, $value);
}

$dbPath = createTempDbPath('status-frontend-cache');
$database = new Database($dbPath);
$pdo = $database->getPdo();
$now = time();

$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['admin_password', 'pass']);
$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['traffic_threshold', '95']);
$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['api_interval', '600']);
$pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)')->execute(['enable_billing', '0']);
$pdo->prepare('INSERT INTO accounts (
    cloud_provider, access_key_id, access_key_secret, region_id, instance_id,
    project_id, security_group_id, max_traffic, schedule_enabled, start_time,
    stop_time, traffic_used, instance_status, updated_at, remark
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
    'fake-context',
    'fake-ak',
    'fake-sk',
    'region-1',
    'instance-1',
    'project-1',
    'sg-1',
    100,
    0,
    '',
    '',
    12.5,
    'Running',
    $now,
    'cached account',
]);

$manager = new ConfigManager($database);
$provider = new FakeContextProvider();
$app = (new ReflectionClass(AliyunTrafficCheck::class))->newInstanceWithoutConstructor();
setPrivateProperty($app, 'db', $database);
setPrivateProperty($app, 'configManager', $manager);
setPrivateProperty($app, 'providerFactory', new FakeProviderFactory($provider));
setPrivateProperty($app, 'initError', null);

$status = $app->getStatusForFrontend();

assertSame(0, $provider->describeCalls, 'fresh cached context account should not call provider describeAccountContext');
assertSame(1, count($status['data']), 'one status item should be returned');
assertSame('Running', $status['data'][0]['instanceStatus'], 'cached status should be returned');
assertSame(12.5, $status['data'][0]['flow_used'], 'cached traffic should be returned');

pass('getStatusForFrontend uses cache for fresh context-capable accounts');
