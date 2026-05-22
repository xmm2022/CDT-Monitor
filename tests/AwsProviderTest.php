<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/AwsProvider.php';

$provider = new AwsProvider();

$caps = $provider->getCapabilities([]);
assertSame(true, $caps['instance_start_stop'], 'AWS should support instance start/stop');
assertSame(true, $caps['security_group_manage'], 'AWS should support security groups');
assertSame(false, $caps['traffic_monitor'], 'AWS traffic is out of scope');

$reservation = [
    'Instances' => [[
        'InstanceId' => 'i-123',
        'State' => ['Name' => 'running'],
        'PublicIpAddress' => '5.6.7.8',
        'Tags' => [['Key' => 'Name', 'Value' => 'aws-web']],
        'SecurityGroups' => [
            ['GroupId' => 'sg-1', 'GroupName' => 'web'],
        ],
    ]],
];

$groups = [[
    'security_group_id' => 'sg-1',
    'security_group_name' => 'web',
    'description' => '',
    'vpc_id' => 'vpc-1',
    'rules' => [],
]];

$context = $provider->buildContextFromInstance(['instance_id' => 'i-123'], $reservation['Instances'][0], $groups);
assertSame('success', $context['discoveryStatus'], 'context status');
assertSame('aws-web', $context['instanceName'], 'tag name');
assertSame('Running', $context['instanceStatus'], 'status');
assertSame('5.6.7.8', $context['publicIp'], 'public ip');
assertSame(1, $context['securityGroupCount'], 'group count');

$rule = $provider->normalizeIpPermission([
    'IpProtocol' => 'tcp',
    'FromPort' => 443,
    'ToPort' => 443,
    'IpRanges' => [[
        'CidrIp' => '0.0.0.0/0',
        'Description' => 'https',
    ]],
    'SecurityGroupRuleId' => 'sgr-1',
]);
assertSame('sgr-1', $rule['security_group_rule_id'], 'rule id');
assertSame('TCP', $rule['ip_protocol'], 'protocol');
assertSame('443/443', $rule['port_range'], 'port range');
assertSame('0.0.0.0/0', $rule['source_cidr_ip'], 'source');

pass('AwsProvider normalizers');
