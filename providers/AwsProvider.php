<?php

require_once __DIR__ . '/CloudProviderInterface.php';
require_once __DIR__ . '/CloudInstanceContextInterface.php';
require_once __DIR__ . '/InstanceContextHelpers.php';

use Aws\Ec2\Ec2Client;

class AwsProvider implements CloudProviderInterface, CloudInstanceContextInterface
{
    use InstanceContextHelpers;

    public function getProviderKey(): string
    {
        return 'aws';
    }

    public function getCapabilities(array $account): array
    {
        return [
            'traffic_monitor' => false,
            'security_group_manage' => true,
            'instance_start_stop' => true,
            'stop_charging' => false,
            'billing_summary' => false,
            'schedule_manage' => false,
            'per_account_proxy' => true,
            'site_type_select' => false,
            'region_picker' => true,
        ];
    }

    public function describeAccountContext(array $account): array
    {
        $missing = $this->getMissingFields($account);
        if (!empty($missing)) {
            return $this->buildIncompleteInstanceContext($account, 'AWS 账号缺少 ' . implode(' / ', $missing));
        }

        try {
            $instance = $this->fetchInstance($account);
            $groups = $this->loadSecurityGroups($account, $instance['SecurityGroups'] ?? []);
            return $this->buildContextFromInstance($account, $instance, $groups);
        } catch (\Throwable $e) {
            return $this->buildErrorInstanceContext($account, trim($e->getMessage()) ?: 'AWS 实例发现失败');
        }
    }

    public function buildContextFromInstance(array $account, array $instance, array $groups): array
    {
        return $this->buildSuccessInstanceContext($account, [
            'instanceId' => $instance['InstanceId'] ?? ($account['instance_id'] ?? ''),
            'instanceName' => $this->extractNameTag($instance['Tags'] ?? []),
            'instanceStatus' => $this->mapAwsStatus($instance['State']['Name'] ?? ''),
            'publicIp' => $instance['PublicIpAddress'] ?? '',
            'securityGroups' => $groups,
            'securityGroupCount' => count($groups),
            'securityGroupNames' => $this->collectSecurityGroupNames($groups),
        ]);
    }

    public function normalizeIpPermission(array $permission): array
    {
        $ipRange = $permission['IpRanges'][0] ?? [];
        $protocol = strtoupper((string) ($permission['IpProtocol'] ?? 'TCP'));
        $from = (string) ($permission['FromPort'] ?? -1);
        $to = (string) ($permission['ToPort'] ?? $from);

        return [
            'security_group_rule_id' => (string) ($permission['SecurityGroupRuleId'] ?? ''),
            'ip_protocol' => $protocol === '-1' ? 'ALL' : $protocol,
            'port_range' => ($from === '-1') ? '-1/-1' : "{$from}/{$to}",
            'source_cidr_ip' => $ipRange['CidrIp'] ?? '0.0.0.0/0',
            'source_display' => $ipRange['CidrIp'] ?? '0.0.0.0/0',
            'description' => $ipRange['Description'] ?? '',
            'direction' => 'ingress',
        ];
    }

    public function getTraffic(array $account)
    {
        return -1;
    }

    public function getInstanceStatus(array $account)
    {
        $context = $this->describeAccountContext($account);
        return $context['instanceStatus'] ?? 'Unknown';
    }

    public function controlInstance(array $account, string $action, string $shutdownMode = 'KeepCharging')
    {
        $client = $this->buildClient($account);
        $action = strtolower(trim($action));

        if ($action === 'start') {
            $client->startInstances(['InstanceIds' => [$account['instance_id']]]);
            return true;
        }

        if ($action === 'stop') {
            $client->stopInstances(['InstanceIds' => [$account['instance_id']]]);
            return true;
        }

        throw new Exception('不支持的 AWS 实例操作');
    }

    public function getAccountBalance(array $account)
    {
        throw new Exception('AWS 账号当前版本暂不支持账单摘要');
    }

    public function getInstanceBill(array $account, string $billingCycle)
    {
        throw new Exception('AWS 账号当前版本暂不支持实例账单查询');
    }

    public function getBillOverview(array $account, string $billingCycle)
    {
        throw new Exception('AWS 账号当前版本暂不支持账单总览');
    }

    public function getInstanceSecurityGroups(array $account)
    {
        $context = $this->describeAccountContext($account);
        return $context['securityGroups'] ?? [];
    }

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $client = $this->buildClient($account);
        [$from, $to] = $this->parsePortRange($rule['port_range'] ?? '-1/-1');

        $client->authorizeSecurityGroupIngress([
            'GroupId' => $securityGroupId,
            'IpPermissions' => [[
                'IpProtocol' => strtolower($rule['ip_protocol'] ?? 'tcp'),
                'FromPort' => $from,
                'ToPort' => $to,
                'IpRanges' => [[
                    'CidrIp' => $rule['source_cidr_ip'] ?? '0.0.0.0/0',
                    'Description' => $rule['description'] ?? '',
                ]],
            ]],
        ]);

        return true;
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $client = $this->buildClient($account);
        $ruleId = trim((string) ($rule['security_group_rule_id'] ?? ''));

        if ($ruleId !== '') {
            $client->revokeSecurityGroupIngress([
                'GroupId' => $securityGroupId,
                'SecurityGroupRuleIds' => [$ruleId],
            ]);
            return true;
        }

        [$from, $to] = $this->parsePortRange($rule['port_range'] ?? '-1/-1');
        $client->revokeSecurityGroupIngress([
            'GroupId' => $securityGroupId,
            'IpPermissions' => [[
                'IpProtocol' => strtolower($rule['ip_protocol'] ?? 'tcp'),
                'FromPort' => $from,
                'ToPort' => $to,
                'IpRanges' => [['CidrIp' => $rule['source_cidr_ip'] ?? '0.0.0.0/0']],
            ]],
        ]);

        return true;
    }

    private function buildClient(array $account): Ec2Client
    {
        return new Ec2Client([
            'version' => 'latest',
            'region' => $account['region_id'],
            'credentials' => [
                'key' => $account['access_key_id'],
                'secret' => $account['access_key_secret'],
            ],
        ]);
    }

    private function fetchInstance(array $account): array
    {
        $result = $this->buildClient($account)->describeInstances([
            'InstanceIds' => [$account['instance_id']],
        ]);
        $reservations = $result['Reservations'] ?? [];
        $instances = $reservations[0]['Instances'] ?? [];
        if (empty($instances[0])) {
            throw new Exception('AWS 未找到指定实例');
        }

        return $instances[0];
    }

    private function loadSecurityGroups(array $account, array $instanceGroups): array
    {
        $ids = [];
        foreach ($instanceGroups as $group) {
            if (!empty($group['GroupId'])) {
                $ids[] = $group['GroupId'];
            }
        }

        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return [];
        }

        $result = $this->buildClient($account)->describeSecurityGroups(['GroupIds' => $ids]);
        $groups = [];
        foreach ($result['SecurityGroups'] ?? [] as $group) {
            $rules = [];
            foreach ($group['IpPermissions'] ?? [] as $permission) {
                $rules[] = $this->normalizeIpPermission($permission);
            }
            $groups[] = [
                'security_group_id' => $group['GroupId'],
                'security_group_name' => $group['GroupName'] ?? $group['GroupId'],
                'description' => $group['Description'] ?? '',
                'vpc_id' => $group['VpcId'] ?? '',
                'rules' => $rules,
            ];
        }

        return $groups;
    }

    private function getMissingFields(array $account): array
    {
        $fields = [];
        foreach ([
            'access_key_id' => 'Access Key ID',
            'access_key_secret' => 'Secret Access Key',
            'region_id' => 'Region',
            'instance_id' => 'Instance ID',
        ] as $key => $label) {
            if (trim((string) ($account[$key] ?? '')) === '') {
                $fields[] = $label;
            }
        }

        return $fields;
    }

    private function mapAwsStatus(string $status): string
    {
        return $this->normalizeInstanceStatus($status, [
            'RUNNING' => 'Running',
            'STOPPED' => 'Stopped',
            'STOPPING' => 'Stopping',
            'PENDING' => 'Pending',
            'SHUTTING-DOWN' => 'Stopping',
        ]);
    }

    private function extractNameTag(array $tags): string
    {
        foreach ($tags as $tag) {
            if (($tag['Key'] ?? '') === 'Name') {
                return (string) ($tag['Value'] ?? '');
            }
        }

        return '';
    }

    private function parsePortRange(string $portRange): array
    {
        if ($portRange === '-1/-1') {
            return [-1, -1];
        }
        if (strpos($portRange, '/') !== false) {
            [$from, $to] = explode('/', $portRange, 2);
            return [(int) $from, (int) $to];
        }

        $port = (int) $portRange;
        return [$port, $port];
    }
}
