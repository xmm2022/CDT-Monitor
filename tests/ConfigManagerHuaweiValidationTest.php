<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';

$dbPath = createTempDbPath('config-manager');
$database = new Database($dbPath);
$manager = new ConfigManager($database);

$basePayload = [
    'admin_password' => 'pass',
    'traffic_threshold' => 95,
    'enable_schedule_email' => false,
    'shutdown_mode' => 'KeepCharging',
    'threshold_action' => 'stop_and_notify',
    'keep_alive' => false,
    'api_interval' => 600,
    'enable_billing' => false,
    'Notification' => [],
];

$missingInstancePayload = $basePayload;
$missingInstancePayload['Accounts'] = [[
    'cloudProvider' => 'huaweicloud',
    'AccessKeyId' => 'hw-ak',
    'AccessKeySecret' => 'hw-sk',
    'regionId' => 'ap-southeast-3',
    'instanceId' => '',
    'projectId' => 'project-1',
    'securityGroupId' => '',
    'maxTraffic' => 0,
    'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
    'remark' => '',
    'siteType' => 'china',
    'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => ''],
]];

$result = $manager->updateConfig($missingInstancePayload);
assertSame(false, $result, 'huaweicloud account without instanceId should be rejected');

$validPayload = $basePayload;
$validPayload['Accounts'] = [[
    'cloudProvider' => 'huaweicloud',
    'AccessKeyId' => 'hw-ak',
    'AccessKeySecret' => 'hw-sk',
    'regionId' => 'ap-southeast-3',
    'instanceId' => 'server-1',
    'projectId' => 'project-1',
    'securityGroupId' => '',
    'maxTraffic' => 0,
    'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
    'remark' => '',
    'siteType' => 'china',
    'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => ''],
]];

$result = $manager->updateConfig($validPayload);
assertSame(true, $result, 'huaweicloud account with instanceId should be accepted');

pass('ConfigManager Huawei validation');
