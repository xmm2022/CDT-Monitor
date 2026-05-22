<?php

require_once __DIR__ . '/CloudProviderInterface.php';
require_once __DIR__ . '/CloudInstanceContextInterface.php';
require_once __DIR__ . '/InstanceContextHelpers.php';

use TencentCloud\Common\Credential;
use TencentCloud\Common\Profile\ClientProfile;
use TencentCloud\Common\Profile\HttpProfile;
use TencentCloud\Cvm\V20170312\CvmClient;
use TencentCloud\Cvm\V20170312\Models\DescribeInstancesRequest;
use TencentCloud\Cvm\V20170312\Models\StartInstancesRequest;
use TencentCloud\Cvm\V20170312\Models\StopInstancesRequest;
use TencentCloud\Vpc\V20170312\Models\CreateSecurityGroupPoliciesRequest;
use TencentCloud\Vpc\V20170312\Models\DeleteSecurityGroupPoliciesRequest;
use TencentCloud\Vpc\V20170312\Models\DescribeSecurityGroupPoliciesRequest;
use TencentCloud\Vpc\V20170312\Models\DescribeSecurityGroupsRequest;
use TencentCloud\Vpc\V20170312\VpcClient;

class TencentCloudProvider implements CloudProviderInterface, CloudInstanceContextInterface
{
    use InstanceContextHelpers;

    public function getProviderKey(): string
    {
        return 'tencentcloud';
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
            return $this->buildIncompleteInstanceContext($account, '腾讯云账号缺少 ' . implode(' / ', $missing));
        }

        try {
            $instance = $this->fetchInstance($account);
            $groups = $this->loadSecurityGroups($account, $instance['SecurityGroupIds'] ?? []);
            return $this->buildContextFromParts($account, $instance, $groups);
        } catch (\Throwable $e) {
            return $this->buildErrorInstanceContext($account, trim($e->getMessage()) ?: '腾讯云实例发现失败');
        }
    }

    public function buildContextFromParts(array $account, array $instance, array $groups): array
    {
        $publicIps = $instance['PublicIpAddresses'] ?? [];

        return $this->buildSuccessInstanceContext($account, [
            'instanceId' => $instance['InstanceId'] ?? ($account['instance_id'] ?? ''),
            'instanceName' => $instance['InstanceName'] ?? '',
            'instanceStatus' => $this->mapTencentStatus($instance['InstanceState'] ?? ''),
            'publicIp' => $publicIps[0] ?? '',
            'securityGroups' => $groups,
            'securityGroupCount' => count($groups),
            'securityGroupNames' => $this->collectSecurityGroupNames($groups),
        ]);
    }

    public function normalizePolicy(array $policy): array
    {
        $protocol = strtoupper((string) ($policy['Protocol'] ?? 'TCP'));
        $port = trim((string) ($policy['Port'] ?? ''));
        $source = $policy['CidrBlock'] ?? ($policy['Ipv6CidrBlock'] ?? '0.0.0.0/0');

        return [
            'security_group_rule_id' => (string) ($policy['PolicyIndex'] ?? ''),
            'ip_protocol' => $protocol,
            'port_range' => $this->normalizeTencentPort($protocol, $port),
            'source_cidr_ip' => $source,
            'source_display' => $source,
            'description' => $policy['PolicyDescription'] ?? '',
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
        $client = $this->buildCvmClient($account);
        $action = strtolower(trim($action));

        if ($action === 'start') {
            $request = new StartInstancesRequest();
            $request->fromJsonString(json_encode(['InstanceIds' => [$account['instance_id']]]));
            $client->StartInstances($request);
            return true;
        }

        if ($action === 'stop') {
            $request = new StopInstancesRequest();
            $request->fromJsonString(json_encode(['InstanceIds' => [$account['instance_id']]]));
            $client->StopInstances($request);
            return true;
        }

        throw new Exception('不支持的腾讯云实例操作');
    }

    public function getAccountBalance(array $account)
    {
        throw new Exception('腾讯云账号当前版本暂不支持账单摘要');
    }

    public function getInstanceBill(array $account, string $billingCycle)
    {
        throw new Exception('腾讯云账号当前版本暂不支持实例账单查询');
    }

    public function getBillOverview(array $account, string $billingCycle)
    {
        throw new Exception('腾讯云账号当前版本暂不支持账单总览');
    }

    public function getInstanceSecurityGroups(array $account)
    {
        $context = $this->describeAccountContext($account);
        return $context['securityGroups'] ?? [];
    }

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $request = new CreateSecurityGroupPoliciesRequest();
        $request->fromJsonString(json_encode([
            'SecurityGroupId' => $securityGroupId,
            'SecurityGroupPolicySet' => [
                'Ingress' => [[
                    'Protocol' => strtoupper($rule['ip_protocol'] ?? 'TCP'),
                    'Port' => $this->formatTencentPort($rule['port_range'] ?? ''),
                    'CidrBlock' => $rule['source_cidr_ip'] ?? '0.0.0.0/0',
                    'Action' => 'ACCEPT',
                    'PolicyDescription' => $rule['description'] ?? '',
                ]],
            ],
        ]));

        $this->buildVpcClient($account)->CreateSecurityGroupPolicies($request);
        return true;
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $policy = [];
        $policyIndex = trim((string) ($rule['security_group_rule_id'] ?? ''));
        if ($policyIndex !== '') {
            $policy['PolicyIndex'] = (int) $policyIndex;
        } else {
            $policy = [
                'Protocol' => strtoupper($rule['ip_protocol'] ?? 'TCP'),
                'Port' => $this->formatTencentPort($rule['port_range'] ?? ''),
                'CidrBlock' => $rule['source_cidr_ip'] ?? '0.0.0.0/0',
                'Action' => 'ACCEPT',
                'PolicyDescription' => $rule['description'] ?? '',
            ];
        }

        $request = new DeleteSecurityGroupPoliciesRequest();
        $request->fromJsonString(json_encode([
            'SecurityGroupId' => $securityGroupId,
            'SecurityGroupPolicySet' => [
                'Ingress' => [$policy],
            ],
        ]));

        $this->buildVpcClient($account)->DeleteSecurityGroupPolicies($request);
        return true;
    }

    private function getMissingFields(array $account): array
    {
        $fields = [];
        foreach ([
            'access_key_id' => 'SecretId',
            'access_key_secret' => 'SecretKey',
            'region_id' => 'Region',
            'instance_id' => 'Instance ID',
        ] as $key => $label) {
            if (trim((string) ($account[$key] ?? '')) === '') {
                $fields[] = $label;
            }
        }
        return $fields;
    }

    private function buildCvmClient(array $account): CvmClient
    {
        return new CvmClient(
            $this->buildCredential($account),
            $account['region_id'],
            $this->buildClientProfile('cvm.tencentcloudapi.com')
        );
    }

    private function buildVpcClient(array $account): VpcClient
    {
        return new VpcClient(
            $this->buildCredential($account),
            $account['region_id'],
            $this->buildClientProfile('vpc.tencentcloudapi.com')
        );
    }

    private function buildCredential(array $account): Credential
    {
        return new Credential($account['access_key_id'], $account['access_key_secret']);
    }

    private function buildClientProfile(string $endpoint): ClientProfile
    {
        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint($endpoint);
        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);
        return $clientProfile;
    }

    private function fetchInstance(array $account): array
    {
        $request = new DescribeInstancesRequest();
        $request->fromJsonString(json_encode(['InstanceIds' => [$account['instance_id']]]));
        $response = $this->buildCvmClient($account)->DescribeInstances($request);
        $payload = $this->modelToArray($response);
        $instances = $payload['InstanceSet'] ?? [];
        if (empty($instances[0])) {
            throw new Exception('腾讯云未找到指定实例');
        }
        return $instances[0];
    }

    private function loadSecurityGroups(array $account, array $groupIds): array
    {
        $groupIds = array_values(array_filter(array_unique(array_map('strval', $groupIds))));
        if (empty($groupIds)) {
            return [];
        }

        $metadata = $this->loadSecurityGroupMetadata($account, $groupIds);
        $groups = [];
        foreach ($groupIds as $groupId) {
            $info = $metadata[$groupId] ?? [];
            $groups[] = $this->loadSecurityGroupPolicies($account, $groupId, $info);
        }

        return $groups;
    }

    private function loadSecurityGroupMetadata(array $account, array $groupIds): array
    {
        $request = new DescribeSecurityGroupsRequest();
        $request->fromJsonString(json_encode([
            'SecurityGroupIds' => $groupIds,
            'Limit' => (string) min(count($groupIds), 100),
        ]));
        $payload = $this->modelToArray($this->buildVpcClient($account)->DescribeSecurityGroups($request));
        $metadata = [];
        foreach ($payload['SecurityGroupSet'] ?? [] as $group) {
            $id = (string) ($group['SecurityGroupId'] ?? '');
            if ($id !== '') {
                $metadata[$id] = $group;
            }
        }
        return $metadata;
    }

    private function loadSecurityGroupPolicies(array $account, string $groupId, array $metadata = []): array
    {
        $request = new DescribeSecurityGroupPoliciesRequest();
        $request->fromJsonString(json_encode(['SecurityGroupId' => $groupId]));
        $payload = $this->modelToArray($this->buildVpcClient($account)->DescribeSecurityGroupPolicies($request));
        $ingress = $payload['SecurityGroupPolicySet']['Ingress'] ?? [];

        $rules = [];
        foreach ($ingress as $policy) {
            $rules[] = $this->normalizePolicy($policy);
        }

        return [
            'security_group_id' => $groupId,
            'security_group_name' => $metadata['SecurityGroupName'] ?? $groupId,
            'description' => $metadata['SecurityGroupDesc'] ?? '',
            'vpc_id' => '',
            'rules' => $rules,
        ];
    }

    private function modelToArray($model): array
    {
        if (method_exists($model, 'toJsonString')) {
            $decoded = json_decode($model->toJsonString(), true);
            return is_array($decoded) ? $decoded : [];
        }

        return json_decode(json_encode($model), true) ?: [];
    }

    private function mapTencentStatus(string $status): string
    {
        return $this->normalizeInstanceStatus($status, [
            'RUNNING' => 'Running',
            'STOPPED' => 'Stopped',
            'STOPPING' => 'Stopping',
            'STARTING' => 'Starting',
            'PENDING' => 'Pending',
            'LAUNCH_FAILED' => 'Unknown',
        ]);
    }

    private function normalizeTencentPort(string $protocol, string $port): string
    {
        $port = strtolower(trim($port));
        if ($protocol === 'ICMP' || $protocol === 'ICMPV6' || $protocol === 'ALL' || $port === '' || $port === 'all') {
            return '-1/-1';
        }
        if (strpos($port, '-') !== false) {
            [$start, $end] = explode('-', $port, 2);
            return trim($start) . '/' . trim($end);
        }
        if (strpos($port, '/') !== false) {
            return $port;
        }
        return $port . '/' . $port;
    }

    private function formatTencentPort(string $portRange): string
    {
        $portRange = trim($portRange);
        if ($portRange === '' || $portRange === '-1/-1') {
            return 'ALL';
        }
        if (strpos($portRange, '/') !== false) {
            [$start, $end] = explode('/', $portRange, 2);
            return $start === $end ? $start : $start . '-' . $end;
        }
        return $portRange;
    }
}
