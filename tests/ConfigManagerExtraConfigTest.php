<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';

$dbPath = createTempDbPath('config-extra-config');
$database = new Database($dbPath);
$manager = new ConfigManager($database);

$payload = [
    'admin_password' => 'pass',
    'traffic_threshold' => 95,
    'enable_schedule_email' => false,
    'shutdown_mode' => 'KeepCharging',
    'threshold_action' => 'stop_and_notify',
    'keep_alive' => false,
    'api_interval' => 600,
    'enable_billing' => false,
    'Notification' => [],
    'Accounts' => [[
        'cloudProvider' => 'gcp',
        'AccessKeyId' => '',
        'AccessKeySecret' => '',
        'regionId' => 'asia-east1-a',
        'instanceId' => 'instance-1',
        'projectId' => 'project-1',
        'securityGroupId' => '',
        'maxTraffic' => 0,
        'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
        'remark' => 'gcp vm',
        'siteType' => 'china',
        'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => ''],
        'extraConfig' => [
            'zone' => 'asia-east1-a',
            'network' => 'default',
            'target_tags' => 'web,ssh',
            'firewall_rule_prefix' => 'cdt-monitor-',
            'service_account_json' => '{"type":"service_account","project_id":"project-1"}',
        ],
    ]],
];

$result = $manager->updateConfig($payload);
assertSame(true, $result, 'valid gcp extraConfig should be accepted');

$accounts = $manager->getAccounts();
assertSame(1, count($accounts), 'one account should be persisted');
assertSame('gcp', $accounts[0]['cloud_provider'], 'provider should persist');

$extra = json_decode($accounts[0]['extra_config'], true);
assertSame('asia-east1-a', $extra['zone'], 'zone should persist in extra_config');
assertSame('cdt-monitor-', $extra['firewall_rule_prefix'], 'prefix should persist in extra_config');
assertSame('{"type":"service_account","project_id":"project-1"}', $extra['service_account_json'], 'service account json should persist verbatim');

$invalid = $payload;
$invalid['Accounts'][0]['extraConfig']['service_account_json'] = '';
$result = $manager->updateConfig($invalid);
assertSame(false, $result, 'gcp account without service_account_json should be rejected');
assertContains('GCP', $manager->getLastError(), 'error should mention GCP');

pass('ConfigManager extra_config persistence and validation');
