<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/TencentCloudProvider.php';

$provider = new TencentCloudProvider();

$caps = $provider->getCapabilities([]);
assertSame(true, $caps['instance_start_stop'], 'Tencent Cloud should support instance start/stop');
assertSame(true, $caps['security_group_manage'], 'Tencent Cloud should support security groups');
assertSame(false, $caps['traffic_monitor'], 'Tencent Cloud traffic is out of scope');

$instance = [
    'InstanceId' => 'ins-1',
    'InstanceName' => 'tx-web',
    'InstanceState' => 'RUNNING',
    'PublicIpAddresses' => ['1.2.3.4'],
    'SecurityGroupIds' => ['sg-1', 'sg-2'],
];

$groups = [[
    'security_group_id' => 'sg-1',
    'security_group_name' => 'web',
    'description' => '',
    'vpc_id' => '',
    'rules' => [],
], [
    'security_group_id' => 'sg-2',
    'security_group_name' => 'admin',
    'description' => '',
    'vpc_id' => '',
    'rules' => [],
]];

$context = $provider->buildContextFromParts(['instance_id' => 'ins-1'], $instance, $groups);
assertSame('success', $context['discoveryStatus'], 'context status');
assertSame('tx-web', $context['instanceName'], 'instance name');
assertSame('Running', $context['instanceStatus'], 'status mapping');
assertSame('1.2.3.4', $context['publicIp'], 'public ip');
assertSame(2, $context['securityGroupCount'], 'security group count');
assertSame(['web', 'admin'], $context['securityGroupNames'], 'security group names');

$rule = $provider->normalizePolicy([
    'PolicyIndex' => 3,
    'Protocol' => 'TCP',
    'Port' => '80',
    'CidrBlock' => '0.0.0.0/0',
    'Action' => 'ACCEPT',
    'PolicyDescription' => 'http',
]);
assertSame('3', $rule['security_group_rule_id'], 'policy index should become rule id');
assertSame('TCP', $rule['ip_protocol'], 'protocol');
assertSame('80/80', $rule['port_range'], 'single port normalization');
assertSame('0.0.0.0/0', $rule['source_cidr_ip'], 'source cidr');

pass('TencentCloudProvider normalizers');
