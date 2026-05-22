<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/HuaweiCloudProvider.php';

$provider = new HuaweiCloudProvider();

$capabilities = $provider->getCapabilities([]);
assertSame(true, $capabilities['instance_start_stop'], 'HuaweiCloud should expose instance start/stop capability');
assertSame(true, $capabilities['traffic_monitor'], 'HuaweiCloud should expose traffic monitor capability');

if (!method_exists($provider, 'describeAccountContext')) {
    fail('describeAccountContext method is missing');
}

if (!method_exists($provider, 'describeAccountContextFromParts')) {
    fail('describeAccountContextFromParts method is missing');
}

$account = [
    'cloud_provider' => 'huaweicloud',
    'access_key_id' => 'hw-ak',
    'access_key_secret' => 'hw-sk',
    'region_id' => 'ap-southeast-3',
    'project_id' => 'project-1',
    'instance_id' => 'server-1',
    'security_group_id' => '',
];

$server = [
    'id' => 'server-1',
    'name' => 'ecs-demo',
    'status' => 'ACTIVE',
    'publicIp' => '1.2.3.4',
    'securityGroups' => [
        ['id' => 'sg-1', 'name' => 'web'],
        ['id' => 'sg-2', 'name' => 'admin'],
    ],
];

$groups = [
    [
        'security_group_id' => 'sg-1',
        'security_group_name' => 'web',
        'description' => 'web ports',
        'vpc_id' => '',
        'rules' => [],
    ],
    [
        'security_group_id' => 'sg-2',
        'security_group_name' => 'admin',
        'description' => 'admin ports',
        'vpc_id' => '',
        'rules' => [],
    ],
];

$summary = $provider->describeAccountContextFromParts($account, $server, $groups);

assertSame('success', $summary['discoveryStatus'], 'instance discovery status');
assertSame('instance', $summary['discoveryMode'], 'instance discovery mode');
assertSame(false, $summary['usingFallbackSecurityGroup'], 'should not be fallback mode');
assertSame('ecs-demo', $summary['instanceName'], 'instance name');
assertSame('1.2.3.4', $summary['publicIp'], 'public ip');
assertSame(2, $summary['securityGroupCount'], 'security group count');
assertSame('Running', $summary['instanceStatus'], 'status mapping');
assertSame(['web', 'admin'], $summary['securityGroupNames'], 'security group names');

if (!method_exists($provider, 'inferServerFromListBySecurityGroup')) {
    fail('inferServerFromListBySecurityGroup method is missing');
}

$inferred = $provider->inferServerFromListBySecurityGroup([
    [
        'id' => 'server-1',
        'name' => 'ecs-demo',
        'status' => 'ACTIVE',
        'publicIp' => '1.2.3.4',
        'securityGroups' => [
            ['id' => 'sg-1', 'name' => 'web'],
        ],
    ],
    [
        'id' => 'server-2',
        'name' => 'ecs-other',
        'status' => 'SHUTOFF',
        'publicIp' => '5.6.7.8',
        'securityGroups' => [
            ['id' => 'sg-9', 'name' => 'other'],
        ],
    ],
], 'sg-1');

assertSame('server-1', $inferred['id'], 'should infer unique server by security group');

$ambiguous = $provider->inferServerFromListBySecurityGroup([
    [
        'id' => 'server-1',
        'name' => 'ecs-demo',
        'status' => 'ACTIVE',
        'publicIp' => '1.2.3.4',
        'securityGroups' => [
            ['id' => 'sg-1', 'name' => 'web'],
        ],
    ],
    [
        'id' => 'server-2',
        'name' => 'ecs-other',
        'status' => 'SHUTOFF',
        'publicIp' => '5.6.7.8',
        'securityGroups' => [
            ['id' => 'sg-1', 'name' => 'web'],
        ],
    ],
], 'sg-1');

assertSame(null, $ambiguous, 'ambiguous security group should not auto-select a server');

if (!method_exists($provider, 'buildTrafficFromMetricSamples')) {
    fail('buildTrafficFromMetricSamples method is missing');
}

$traffic = $provider->buildTrafficFromMetricSamples([
    ['sum' => 1073741824],
    ['sum' => 2147483648],
]);
assertSame(3.0, $traffic, 'traffic samples should sum and convert to GB');

if (!method_exists($provider, 'buildTrafficFromRateDatapoints')) {
    fail('buildTrafficFromRateDatapoints method is missing');
}

$rateTraffic = $provider->buildTrafficFromRateDatapoints([
    ['average' => 1024, 'period' => 3600],
    ['average' => 2048, 'period' => 1800],
]);
assertSame(0.007, $rateTraffic, 'rate datapoints should convert byte/s to traffic GB');

pass('HuaweiCloudProvider discovery summary');
