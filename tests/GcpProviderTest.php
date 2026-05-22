<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/GcpProvider.php';

$provider = new GcpProvider();

$caps = $provider->getCapabilities([]);
assertSame(true, $caps['instance_start_stop'], 'GCP should support start/stop');
assertSame(true, $caps['security_group_manage'], 'GCP should expose firewall rule management through security modal');
assertSame(true, $caps['firewall_rule_model'], 'GCP should mark firewall rule model');

$account = [
    'project_id' => 'project-1',
    'region_id' => 'asia-east1-a',
    'instance_id' => 'gcp-vm',
    'extra_config' => json_encode([
        'zone' => 'asia-east1-a',
        'network' => 'default',
        'target_tags' => 'web,ssh',
        'firewall_rule_prefix' => 'cdt-monitor-',
        'service_account_json' => '{"type":"service_account","project_id":"project-1"}',
    ]),
];

$instance = [
    'name' => 'gcp-vm',
    'status' => 'RUNNING',
    'networkInterfaces' => [[
        'network' => 'https://www.googleapis.com/compute/v1/projects/project-1/global/networks/default',
        'accessConfigs' => [['natIP' => '9.9.9.9']],
    ]],
    'tags' => ['items' => ['web', 'ssh']],
];

$rules = [[
    'name' => 'cdt-monitor-web-80',
    'description' => 'http',
    'network' => 'https://www.googleapis.com/compute/v1/projects/project-1/global/networks/default',
    'direction' => 'INGRESS',
    'sourceRanges' => ['0.0.0.0/0'],
    'allowed' => [['IPProtocol' => 'tcp', 'ports' => ['80']]],
    'targetTags' => ['web'],
]];

$context = $provider->buildContextFromParts($account, $instance, $rules);
assertSame('success', $context['discoveryStatus'], 'context status');
assertSame('gcp-vm', $context['instanceName'], 'instance name');
assertSame('Running', $context['instanceStatus'], 'status mapping');
assertSame('9.9.9.9', $context['publicIp'], 'public ip');
assertSame(1, $context['securityGroupCount'], 'firewall group count');

$rule = $provider->normalizeFirewallRule($rules[0]);
assertSame('cdt-monitor-web-80', $rule['security_group_rule_id'], 'rule name');
assertSame('TCP', $rule['ip_protocol'], 'protocol');
assertSame('80/80', $rule['port_range'], 'port');
assertSame('0.0.0.0/0', $rule['source_cidr_ip'], 'source');

pass('GcpProvider normalizers');
