<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';

function basePayload(): array
{
    return [
        'admin_password' => 'pass',
        'traffic_threshold' => 95,
        'enable_schedule_email' => false,
        'shutdown_mode' => 'KeepCharging',
        'threshold_action' => 'stop_and_notify',
        'keep_alive' => false,
        'api_interval' => 600,
        'enable_billing' => false,
        'Notification' => [],
        'Accounts' => [],
    ];
}

function accountFor(string $provider): array
{
    $account = [
        'cloudProvider' => $provider,
        'AccessKeyId' => 'ak',
        'AccessKeySecret' => 'sk',
        'regionId' => 'ap-region-1',
        'instanceId' => 'instance-1',
        'projectId' => '',
        'securityGroupId' => '',
        'maxTraffic' => 0,
        'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
        'remark' => '',
        'siteType' => 'china',
        'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => ''],
        'extraConfig' => [],
    ];

    if ($provider === 'gcp') {
        $account['AccessKeyId'] = '';
        $account['AccessKeySecret'] = '';
        $account['projectId'] = 'project-1';
        $account['regionId'] = 'asia-east1-a';
        $account['instanceId'] = 'gcp-vm';
        $account['extraConfig'] = [
            'zone' => 'asia-east1-a',
            'network' => 'default',
            'target_tags' => 'web',
            'firewall_rule_prefix' => 'cdt-monitor-',
            'service_account_json' => '{"type":"service_account","project_id":"project-1"}',
        ];
    }

    return $account;
}

$dbPath = createTempDbPath('config-multi-provider');
$manager = new ConfigManager(new Database($dbPath));

$payload = basePayload();
$payload['Accounts'] = [
    accountFor('tencentcloud'),
    accountFor('aws'),
    accountFor('gcp'),
];

assertSame(true, $manager->updateConfig($payload), 'valid multi-provider payload should save');
assertSame(3, count($manager->getAccounts()), 'three accounts should persist');

$invalid = basePayload();
$badAws = accountFor('aws');
$badAws['instanceId'] = '';
$invalid['Accounts'] = [$badAws];
assertSame(false, $manager->updateConfig($invalid), 'aws without instance should fail');

pass('ConfigManager multi-provider validation');
