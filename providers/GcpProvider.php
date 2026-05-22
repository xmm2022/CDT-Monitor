<?php

require_once __DIR__ . '/CloudProviderInterface.php';
require_once __DIR__ . '/CloudInstanceContextInterface.php';
require_once __DIR__ . '/InstanceContextHelpers.php';

use Google\Cloud\Compute\V1\Allowed;
use Google\Cloud\Compute\V1\DeleteFirewallRequest;
use Google\Cloud\Compute\V1\Firewall;
use Google\Cloud\Compute\V1\FirewallsClient;
use Google\Cloud\Compute\V1\GetInstanceRequest;
use Google\Cloud\Compute\V1\InsertFirewallRequest;
use Google\Cloud\Compute\V1\InstancesClient;
use Google\Cloud\Compute\V1\ListFirewallsRequest;
use Google\Cloud\Compute\V1\StartInstanceRequest;
use Google\Cloud\Compute\V1\StopInstanceRequest;

class GcpProvider implements CloudProviderInterface, CloudInstanceContextInterface
{
    use InstanceContextHelpers;

    public function getProviderKey(): string
    {
        return 'gcp';
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
            'per_account_proxy' => false,
            'site_type_select' => false,
            'region_picker' => true,
            'firewall_rule_model' => true,
        ];
    }

    public function describeAccountContext(array $account): array
    {
        $missing = $this->getMissingFields($account);
        if (!empty($missing)) {
            return $this->buildIncompleteInstanceContext($account, 'GCP 账号缺少 ' . implode(' / ', $missing));
        }

        try {
            $instance = $this->fetchInstance($account);
            $rules = $this->listFirewallRules($account, $instance);
            return $this->buildContextFromParts($account, $instance, $rules);
        } catch (\Throwable $e) {
            return $this->buildErrorInstanceContext($account, trim($e->getMessage()) ?: 'GCP 实例发现失败');
        }
    }

    public function buildContextFromParts(array $account, array $instance, array $rules): array
    {
        $group = [
            'security_group_id' => $this->getFirewallGroupId($account, $instance),
            'security_group_name' => '防火墙规则',
            'description' => 'GCP Compute Engine firewall rules',
            'vpc_id' => $this->extractNetworkName($instance),
            'rules' => array_map([$this, 'normalizeFirewallRule'], $rules),
        ];

        return $this->buildSuccessInstanceContext($account, [
            'instanceId' => $instance['name'] ?? ($account['instance_id'] ?? ''),
            'instanceName' => $instance['name'] ?? '',
            'instanceStatus' => $this->mapGcpStatus($instance['status'] ?? ''),
            'publicIp' => $this->extractPublicIp($instance),
            'securityGroups' => [$group],
            'securityGroupCount' => 1,
            'securityGroupNames' => ['防火墙规则'],
        ]);
    }

    public function normalizeFirewallRule(array $rule): array
    {
        $allowed = $rule['allowed'][0] ?? [];
        $protocol = strtoupper((string) ($allowed['IPProtocol'] ?? $allowed['ipProtocol'] ?? $allowed['I_p_protocol'] ?? 'tcp'));
        $ports = $allowed['ports'] ?? [];
        $port = $ports[0] ?? '-1';
        $portRange = strpos((string) $port, '-') !== false
            ? str_replace('-', '/', (string) $port)
            : ((string) $port === '-1' ? '-1/-1' : $port . '/' . $port);

        $sourceRanges = $rule['sourceRanges'] ?? $rule['source_ranges'] ?? ['0.0.0.0/0'];
        $source = $sourceRanges[0] ?? '0.0.0.0/0';

        return [
            'security_group_rule_id' => (string) ($rule['name'] ?? ''),
            'ip_protocol' => $protocol,
            'port_range' => $portRange,
            'source_cidr_ip' => $source,
            'source_display' => $source,
            'description' => $rule['description'] ?? '',
            'direction' => strtolower((string) ($rule['direction'] ?? 'INGRESS')) === 'egress' ? 'egress' : 'ingress',
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
        $extra = $this->decodeExtraConfig($account);
        $project = $account['project_id'];
        $zone = $extra['zone'] ?? $account['region_id'];
        $instance = $account['instance_id'];
        $client = $this->buildInstancesClient($account);
        $action = strtolower(trim($action));

        if ($action === 'start') {
            $client->start(StartInstanceRequest::build($project, $zone, $instance));
            return true;
        }

        if ($action === 'stop') {
            $client->stop(StopInstanceRequest::build($project, $zone, $instance));
            return true;
        }

        throw new Exception('不支持的 GCP 实例操作');
    }

    public function getAccountBalance(array $account)
    {
        throw new Exception('GCP 账号当前版本暂不支持账单摘要');
    }

    public function getInstanceBill(array $account, string $billingCycle)
    {
        throw new Exception('GCP 账号当前版本暂不支持实例账单查询');
    }

    public function getBillOverview(array $account, string $billingCycle)
    {
        throw new Exception('GCP 账号当前版本暂不支持账单总览');
    }

    public function getInstanceSecurityGroups(array $account)
    {
        $context = $this->describeAccountContext($account);
        return $context['securityGroups'] ?? [];
    }

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $extra = $this->decodeExtraConfig($account);
        $prefix = $extra['firewall_rule_prefix'] ?? 'cdt-monitor-';
        $name = $prefix . strtolower(($rule['ip_protocol'] ?? 'tcp') . '-' . str_replace('/', '-', $rule['port_range'] ?? 'all') . '-' . substr(md5($rule['source_cidr_ip'] ?? '0.0.0.0/0'), 0, 8));
        $network = $this->formatNetworkSelfLink($account, $extra['network'] ?? 'default');

        $allowed = (new Allowed())
            ->setIPProtocol(strtolower($rule['ip_protocol'] ?? 'tcp'))
            ->setPorts($this->portsForGcp($rule['port_range'] ?? '-1/-1'));

        $firewall = (new Firewall())
            ->setName($name)
            ->setDescription($rule['description'] ?? '')
            ->setDirection('INGRESS')
            ->setNetwork($network)
            ->setSourceRanges([$rule['source_cidr_ip'] ?? '0.0.0.0/0'])
            ->setAllowed([$allowed])
            ->setTargetTags($this->parseTags($extra['target_tags'] ?? ''));

        $this->buildFirewallsClient($account)->insert(InsertFirewallRequest::build($account['project_id'], $firewall));
        return true;
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $extra = $this->decodeExtraConfig($account);
        $prefix = $extra['firewall_rule_prefix'] ?? 'cdt-monitor-';
        $ruleName = trim((string) ($rule['security_group_rule_id'] ?? ''));

        if ($ruleName === '' || strpos($ruleName, $prefix) !== 0) {
            throw new Exception('GCP 仅允许删除 CDT-Monitor 创建的防火墙规则');
        }

        $this->buildFirewallsClient($account)->delete(DeleteFirewallRequest::build($account['project_id'], $ruleName));
        return true;
    }

    private function buildInstancesClient(array $account): InstancesClient
    {
        return new InstancesClient(['credentials' => $this->decodeServiceAccount($account)]);
    }

    private function buildFirewallsClient(array $account): FirewallsClient
    {
        return new FirewallsClient(['credentials' => $this->decodeServiceAccount($account)]);
    }

    private function decodeServiceAccount(array $account): array
    {
        $extra = $this->decodeExtraConfig($account);
        $decoded = json_decode((string) ($extra['service_account_json'] ?? ''), true);
        if (!is_array($decoded)) {
            throw new Exception('GCP Service Account JSON 格式无效');
        }
        return $decoded;
    }

    private function fetchInstance(array $account): array
    {
        $extra = $this->decodeExtraConfig($account);
        $instance = $this->buildInstancesClient($account)->get(
            GetInstanceRequest::build($account['project_id'], $extra['zone'] ?? $account['region_id'], $account['instance_id'])
        );
        return $this->messageToArray($instance);
    }

    private function listFirewallRules(array $account, array $instance): array
    {
        $extra = $this->decodeExtraConfig($account);
        $prefix = $extra['firewall_rule_prefix'] ?? 'cdt-monitor-';
        $rules = [];

        foreach ($this->buildFirewallsClient($account)->list(ListFirewallsRequest::build($account['project_id'])) as $firewall) {
            $rule = $this->messageToArray($firewall);
            $name = (string) ($rule['name'] ?? '');
            if (strpos($name, $prefix) === 0 || $this->ruleTargetsInstance($rule, $instance, $extra)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    private function messageToArray($message): array
    {
        if (method_exists($message, 'serializeToJsonString')) {
            $decoded = json_decode($message->serializeToJsonString(), true);
            return is_array($decoded) ? $decoded : [];
        }

        return json_decode(json_encode($message), true) ?: [];
    }

    private function getMissingFields(array $account): array
    {
        $extra = $this->decodeExtraConfig($account);
        $fields = [];
        if (trim((string) ($account['project_id'] ?? '')) === '') {
            $fields[] = 'Project ID';
        }
        if (trim((string) ($extra['zone'] ?? $account['region_id'] ?? '')) === '') {
            $fields[] = 'Zone';
        }
        if (trim((string) ($account['instance_id'] ?? '')) === '') {
            $fields[] = 'Instance Name';
        }
        if (trim((string) ($extra['service_account_json'] ?? '')) === '') {
            $fields[] = 'Service Account JSON';
        }
        return $fields;
    }

    private function mapGcpStatus(string $status): string
    {
        return $this->normalizeInstanceStatus($status, [
            'RUNNING' => 'Running',
            'TERMINATED' => 'Stopped',
            'STOPPING' => 'Stopping',
            'STAGING' => 'Starting',
            'PROVISIONING' => 'Pending',
        ]);
    }

    private function extractPublicIp(array $instance): string
    {
        foreach ($instance['networkInterfaces'] ?? $instance['network_interfaces'] ?? [] as $interface) {
            foreach ($interface['accessConfigs'] ?? $interface['access_configs'] ?? [] as $config) {
                if (!empty($config['natIP'])) {
                    return $config['natIP'];
                }
                if (!empty($config['nat_i_p'])) {
                    return $config['nat_i_p'];
                }
            }
        }
        return '';
    }

    private function extractNetworkName(array $instance): string
    {
        $interfaces = $instance['networkInterfaces'] ?? $instance['network_interfaces'] ?? [];
        $network = $interfaces[0]['network'] ?? '';
        if ($network === '') {
            return '';
        }
        $parts = explode('/', $network);
        return end($parts) ?: $network;
    }

    private function getFirewallGroupId(array $account, array $instance): string
    {
        return 'gcp-firewall:' . $this->extractNetworkName($instance);
    }

    private function parseTags(string $tags): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $tags))));
    }

    private function portsForGcp(string $portRange): array
    {
        if ($portRange === '-1/-1') {
            return [];
        }
        if (strpos($portRange, '/') !== false) {
            [$start, $end] = explode('/', $portRange, 2);
            return [$start === $end ? $start : $start . '-' . $end];
        }
        return [$portRange];
    }

    private function ruleTargetsInstance(array $rule, array $instance, array $extra): bool
    {
        $configuredTags = $this->parseTags($extra['target_tags'] ?? '');
        if (empty($configuredTags)) {
            return false;
        }

        $ruleTags = $rule['targetTags'] ?? $rule['target_tags'] ?? [];
        return count(array_intersect($configuredTags, $ruleTags)) > 0;
    }

    private function formatNetworkSelfLink(array $account, string $network): string
    {
        if (strpos($network, '/') !== false) {
            return $network;
        }

        return 'projects/' . $account['project_id'] . '/global/networks/' . $network;
    }
}
