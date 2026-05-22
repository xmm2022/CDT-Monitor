<?php

require_once __DIR__ . '/CloudProviderInterface.php';
require_once __DIR__ . '/CloudInstanceContextInterface.php';
require_once __DIR__ . '/InstanceContextHelpers.php';

use HuaweiCloud\SDK\Core\Auth\BasicCredentials;
use HuaweiCloud\SDK\Core\Http\HttpConfig;
use HuaweiCloud\SDK\Ces\V1\CesClient;
use HuaweiCloud\SDK\Ces\V1\Model\ShowMetricDataRequest;
use HuaweiCloud\SDK\Ecs\V2\EcsClient;
use HuaweiCloud\SDK\Ecs\V2\Model\BatchStartServersOption;
use HuaweiCloud\SDK\Ecs\V2\Model\BatchStartServersRequest;
use HuaweiCloud\SDK\Ecs\V2\Model\BatchStartServersRequestBody;
use HuaweiCloud\SDK\Ecs\V2\Model\BatchStopServersOption;
use HuaweiCloud\SDK\Ecs\V2\Model\BatchStopServersRequest;
use HuaweiCloud\SDK\Ecs\V2\Model\BatchStopServersRequestBody;
use HuaweiCloud\SDK\Ecs\V2\Model\ListCloudServersRequest;
use HuaweiCloud\SDK\Ecs\V2\Model\ServerId;
use HuaweiCloud\SDK\Ecs\V2\Model\ShowServerRequest;
use HuaweiCloud\SDK\Eip\V3\EipClient;
use HuaweiCloud\SDK\Eip\V3\Model\ListPublicipsRequest;
use HuaweiCloud\SDK\Vpc\V3\Model\CreateSecurityGroupRuleOption;
use HuaweiCloud\SDK\Vpc\V3\Model\CreateSecurityGroupRuleRequest;
use HuaweiCloud\SDK\Vpc\V3\Model\CreateSecurityGroupRuleRequestBody;
use HuaweiCloud\SDK\Vpc\V3\Model\DeleteSecurityGroupRuleRequest;
use HuaweiCloud\SDK\Vpc\V3\Model\ShowSecurityGroupRequest;
use HuaweiCloud\SDK\Vpc\V3\VpcClient;

class HuaweiCloudProvider implements CloudProviderInterface, CloudInstanceContextInterface
{
    use InstanceContextHelpers;

    public function getProviderKey(): string
    {
        return 'huaweicloud';
    }

    public function getCapabilities(array $account): array
    {
        return [
            'traffic_monitor' => true,
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

    public function getTraffic(array $account)
    {
        $instanceId = trim((string) ($account['instance_id'] ?? ''));
        if ($instanceId === '') {
            throw new Exception('华为云账号缺少 Instance ID，无法读取公网流量');
        }

        $traffic = $this->getInstanceMonthlyTrafficGb($account, $instanceId);
        if ($traffic !== null) {
            return $traffic;
        }

        $publicIps = $this->listAssociatedPublicIps($account, $instanceId);
        if (empty($publicIps)) {
            throw new Exception('当前实例没有可用的公网流量监控口径');
        }

        $totalBytes = 0.0;
        foreach ($publicIps as $publicIp) {
            $totalBytes += $this->getPublicIpMonthlyTrafficBytes($account, $publicIp);
        }

        return $this->buildTrafficFromMetricSamples([['sum' => $totalBytes]]);
    }

    public function getInstanceStatus(array $account)
    {
        $context = $this->describeAccountContext($account);
        return $context['instanceStatus'] ?? 'Unknown';
    }

    public function controlInstance(array $account, string $action, string $shutdownMode = 'KeepCharging')
    {
        $instanceId = trim((string) ($account['instance_id'] ?? ''));
        if ($instanceId === '') {
            throw new Exception('华为云账号缺少 Instance ID，无法执行开关机');
        }

        $serverId = new ServerId(['id' => $instanceId]);
        $client = $this->buildEcsClient($account);
        $action = strtolower(trim($action));

        if ($action === 'start') {
            $request = new BatchStartServersRequest([
                'body' => new BatchStartServersRequestBody([
                    'osStart' => new BatchStartServersOption([
                        'servers' => [$serverId],
                    ]),
                ]),
            ]);
            $client->batchStartServers($request);
            return true;
        }

        if ($action === 'stop') {
            $request = new BatchStopServersRequest([
                'body' => new BatchStopServersRequestBody([
                    'osStop' => new BatchStopServersOption([
                        'servers' => [$serverId],
                        'type' => 'SOFT',
                    ]),
                ]),
            ]);
            $client->batchStopServers($request);
            return true;
        }

        throw new Exception('不支持的华为云实例操作');
    }

    public function getAccountBalance(array $account)
    {
        throw new Exception('华为云账号当前版本暂不支持账单摘要');
    }

    public function getInstanceBill(array $account, string $billingCycle)
    {
        throw new Exception('华为云账号当前版本暂不支持实例账单查询');
    }

    public function getBillOverview(array $account, string $billingCycle)
    {
        throw new Exception('华为云账号当前版本暂不支持账单总览');
    }

    public function getInstanceSecurityGroups(array $account)
    {
        $context = $this->describeAccountContext($account);
        if (!empty($context['securityGroups'])) {
            return $context['securityGroups'];
        }

        throw new Exception($context['discoveryMessage'] ?: '未发现可管理安全组');
    }

    public function describeAccountContext(array $account): array
    {
        $missingBaseFields = $this->getMissingBaseFields($account);
        if (!empty($missingBaseFields)) {
            return $this->buildIncompleteContext($account, '华为云账号缺少 ' . implode(' / ', $missingBaseFields));
        }

        if (!empty($account['instance_id'])) {
            try {
                $server = $this->fetchServerDetail($account);
                $groups = $this->loadSecurityGroupsFromServer($account, $server);
                return $this->enrichContextWithTraffic($account, $this->describeAccountContextFromParts($account, $server, $groups));
            } catch (\Throwable $e) {
                return $this->buildFallbackContext($account, $this->formatThrowableMessage($e));
            }
        }

        if (!empty($account['security_group_id'])) {
            try {
                $inferredServer = $this->discoverServerFromFallbackSecurityGroup($account);
                if ($inferredServer !== null) {
                    $groups = $this->loadSecurityGroupsFromServer($account, $inferredServer);
                    $context = $this->enrichContextWithTraffic($account, $this->describeAccountContextFromParts($account, $inferredServer, $groups));
                    $context['discoveryMessage'] = '已根据兼容安全组自动识别实例，建议保存配置写回 Instance ID。';
                    return $context;
                }

                return $this->buildFallbackContext($account, '当前兼容安全组匹配到多台实例，请补充 Instance ID');
            } catch (\Throwable $e) {
                return $this->buildFallbackContext($account, $this->formatThrowableMessage($e));
            }
        }

        return $this->buildFallbackContext($account, '缺少 Instance ID，当前只能尝试兼容安全组模式');
    }

    public function buildTrafficFromMetricSamples(array $samples): float
    {
        $totalBytes = 0.0;

        foreach ($samples as $sample) {
            $sum = is_array($sample)
                ? (float) ($sample['sum'] ?? 0)
                : 0.0;
            $totalBytes += $sum;
        }

        return round($totalBytes / (1024 * 1024 * 1024), 3);
    }

    public function buildTrafficFromRateDatapoints(array $datapoints): float
    {
        $totalBytes = 0.0;

        foreach ($datapoints as $point) {
            $average = (float) ($point['average'] ?? 0.0);
            $period = (int) ($point['period'] ?? 0);
            $totalBytes += $average * $period;
        }

        return round($totalBytes / (1024 * 1024 * 1024), 3);
    }

    public function describeAccountContextFromParts(array $account, array $server, array $groups): array
    {
        $securityGroupNames = [];
        foreach ($groups as $group) {
            $name = trim((string) ($group['security_group_name'] ?? ''));
            if ($name !== '') {
                $securityGroupNames[] = $name;
            }
        }

        return [
            'instanceId' => $server['id'] ?? ($account['instance_id'] ?? ''),
            'instanceName' => $server['name'] ?? '',
            'instanceStatus' => $this->mapHuaweiStatus($server['status'] ?? ''),
            'publicIp' => $server['publicIp'] ?? '',
            'securityGroups' => $groups,
            'securityGroupCount' => count($groups),
            'securityGroupNames' => $securityGroupNames,
            'discoveryStatus' => 'success',
            'discoveryMode' => 'instance',
            'discoveryMessage' => '',
            'usingFallbackSecurityGroup' => false,
            'fallbackSecurityGroupId' => '',
            'trafficDataAvailable' => false,
            'trafficUsedGb' => null,
            'trafficError' => '',
        ];
    }

    public function inferServerFromListBySecurityGroup(array $servers, string $securityGroupId): ?array
    {
        $matches = [];

        foreach ($servers as $server) {
            foreach ($server['securityGroups'] ?? [] as $group) {
                if (trim((string) ($group['id'] ?? '')) === $securityGroupId) {
                    $matches[] = $server;
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    public function addSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $client = $this->buildVpcClient($account);

        $request = new CreateSecurityGroupRuleRequest([
            'projectId' => $account['project_id'],
            'body' => new CreateSecurityGroupRuleRequestBody([
                'securityGroupRule' => new CreateSecurityGroupRuleOption([
                    'securityGroupId' => $securityGroupId,
                    'description' => $rule['description'] ?? '',
                    'direction' => 'ingress',
                    'ethertype' => 'IPv4',
                    'protocol' => $this->normalizeProtocol($rule['ip_protocol'] ?? 'TCP'),
                    'multiport' => $this->normalizePortRange($rule['ip_protocol'] ?? 'TCP', $rule['port_range'] ?? ''),
                    'remoteIpPrefix' => $rule['source_cidr_ip'] ?? '0.0.0.0/0',
                    'action' => 'allow',
                    'priority' => '1',
                    'enabled' => true
                ])
            ])
        ]);

        $client->createSecurityGroupRule($request);
        return true;
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $ruleId = trim((string) ($rule['security_group_rule_id'] ?? ''));
        if ($ruleId === '') {
            throw new Exception('华为云删除安全组规则需要规则 ID');
        }

        $client = $this->buildVpcClient($account);
        $request = new DeleteSecurityGroupRuleRequest([
            'projectId' => $account['project_id'],
            'securityGroupRuleId' => $ruleId
        ]);

        $client->deleteSecurityGroupRule($request);
        return true;
    }

    private function buildVpcClient(array $account): VpcClient
    {
        $this->validateBaseAccount($account);

        $credentials = new BasicCredentials(
            $account['access_key_id'],
            $account['access_key_secret'],
            $account['project_id']
        );

        return VpcClient::newBuilder()
            ->withCredentials($credentials)
            ->withHttpConfig($this->buildHttpConfig($account))
            ->withEndpoint($this->buildVpcEndpoint($account['region_id']))
            ->build();
    }

    private function buildEcsClient(array $account): EcsClient
    {
        $this->validateBaseAccount($account);

        $credentials = new BasicCredentials(
            $account['access_key_id'],
            $account['access_key_secret'],
            $account['project_id']
        );

        return EcsClient::newBuilder()
            ->withCredentials($credentials)
            ->withHttpConfig($this->buildHttpConfig($account))
            ->withEndpoint($this->buildEcsEndpoint($account['region_id']))
            ->build();
    }

    private function buildCesClient(array $account): CesClient
    {
        $this->validateBaseAccount($account);

        $credentials = new BasicCredentials(
            $account['access_key_id'],
            $account['access_key_secret'],
            $account['project_id']
        );

        return CesClient::newBuilder()
            ->withCredentials($credentials)
            ->withHttpConfig($this->buildHttpConfig($account))
            ->withEndpoint($this->buildCesEndpoint($account['region_id']))
            ->build();
    }

    private function buildEipClient(array $account): EipClient
    {
        $this->validateBaseAccount($account);

        $credentials = new BasicCredentials(
            $account['access_key_id'],
            $account['access_key_secret'],
            $account['project_id']
        );

        return EipClient::newBuilder()
            ->withCredentials($credentials)
            ->withHttpConfig($this->buildHttpConfig($account))
            ->withEndpoint($this->buildVpcEndpoint($account['region_id']))
            ->build();
    }

    private function buildHttpConfig(array $account): HttpConfig
    {
        $config = HttpConfig::getDefaultConfig()
            ->withConnectTimeout(5)
            ->withTimeout(10);

        if (($account['api_proxy_enabled'] ?? 0) == 1) {
            $host = trim((string) ($account['api_proxy_host'] ?? ''));
            $port = trim((string) ($account['api_proxy_port'] ?? ''));

            if ($host === '' || $port === '') {
                throw new Exception('SOCKS5 代理未完整配置');
            }
            if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new Exception('SOCKS5 代理端口无效');
            }

            $config->withProxyProtocol('socks5h')
                ->withProxyHost($host)
                ->withProxyPort($port);

            $username = trim((string) ($account['api_proxy_user'] ?? ''));
            $password = (string) ($account['api_proxy_pass'] ?? '');
            if ($username !== '' || $password !== '') {
                $config->withProxyUser($username)
                    ->withProxyPassword($password);
            }
        }

        return $config;
    }

    private function validateBaseAccount(array $account): void
    {
        if (empty($account['access_key_id']) || empty($account['access_key_secret'])) {
            throw new Exception('华为云账号缺少 AK/SK');
        }
        if (empty($account['region_id'])) {
            throw new Exception('华为云账号缺少 Region ID');
        }
        if (empty($account['project_id'])) {
            throw new Exception('华为云账号缺少 Project ID');
        }
    }

    private function buildVpcEndpoint(string $regionId): string
    {
        return 'https://vpc.' . $regionId . '.myhuaweicloud.com';
    }

    private function buildEcsEndpoint(string $regionId): string
    {
        return 'https://ecs.' . $regionId . '.myhuaweicloud.com';
    }

    private function buildCesEndpoint(string $regionId): string
    {
        return 'https://ces.' . $regionId . '.myhuaweicloud.com';
    }

    private function normalizeProtocol(string $protocol): ?string
    {
        $protocol = strtoupper(trim($protocol));
        if ($protocol === 'ALL') {
            return null;
        }
        if ($protocol === 'GRE') {
            throw new Exception('华为云安全组当前版本暂不支持 GRE 协议');
        }

        return strtolower($protocol);
    }

    private function normalizePortRange(string $protocol, string $portRange): ?string
    {
        $protocol = strtoupper(trim($protocol));
        $portRange = trim($portRange);

        if ($protocol === 'TCP' || $protocol === 'UDP') {
            if (!preg_match('/^\d{1,5}\/\d{1,5}$/', $portRange)) {
                throw new Exception('华为云端口范围格式无效，请使用 80/80 或 3000/3999');
            }

            [$startPort, $endPort] = array_map('intval', explode('/', $portRange));
            if ($startPort < 1 || $endPort < 1 || $startPort > 65535 || $endPort > 65535 || $startPort > $endPort) {
                throw new Exception('华为云端口范围无效');
            }

            return $startPort === $endPort ? (string) $startPort : ($startPort . '-' . $endPort);
        }

        return null;
    }

    private function fetchServerDetail(array $account): array
    {
        $request = new ShowServerRequest([
            'serverId' => $account['instance_id'],
        ]);

        $response = $this->buildEcsClient($account)->showServer($request);
        $server = $response->getServer();

        if (!$server) {
            throw new Exception('实例不存在或当前 Project 下不可见');
        }

        return [
            'id' => (string) $server->getId(),
            'name' => (string) $server->getName(),
            'status' => (string) $server->getStatus(),
            'publicIp' => $this->extractServerPublicIp($server->getAddresses()),
            'securityGroups' => $this->normalizeServerSecurityGroups($server->getSecurityGroups()),
        ];
    }

    private function listAssociatedPublicIps(array $account, string $instanceId): array
    {
        $request = new ListPublicipsRequest([
            'associateInstanceId' => [$instanceId],
        ]);

        $response = $this->buildEipClient($account)->listPublicips($request);
        $resources = [];

        foreach ($response->getPublicips() ?? [] as $publicIp) {
            $resources[] = [
                'id' => (string) $publicIp->getId(),
                'publicIpAddress' => (string) $publicIp->getPublicIpAddress(),
                'bandwidthId' => $publicIp->getBandwidth() ? (string) $publicIp->getBandwidth()->getId() : '',
            ];
        }

        return $resources;
    }

    private function getPublicIpMonthlyTrafficBytes(array $account, array $publicIp): float
    {
        try {
            return $this->queryMetricBytes($account, 'up_stream', $publicIp) + $this->queryMetricBytes($account, 'down_stream', $publicIp);
        } catch (\Throwable $e) {
            $message = $this->formatThrowableMessage($e);
            if (str_contains($message, 'ces.0017') || str_contains($message, 'no sufficient rights')) {
                throw new Exception('华为云 CES 权限不足，无法读取公网流量');
            }

            throw $e;
        }
    }

    private function queryMetricBytes(array $account, string $metricName, array $publicIp): float
    {
        $now = time();
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));

        $dimensionSets = [
            [['publicip_id', $publicIp['id']]],
        ];

        if (!empty($publicIp['bandwidthId'])) {
            $dimensionSets[] = [['bandwidth_id', $publicIp['bandwidthId']]];
            $dimensionSets[] = [['publicip_id', $publicIp['id']], ['bandwidth_id', $publicIp['bandwidthId']]];
            $dimensionSets[] = [['bandwidth_id', $publicIp['bandwidthId']], ['publicip_id', $publicIp['id']]];
        }

        foreach ($dimensionSets as $dimensions) {
            $requestData = [
                'namespace' => 'SYS.EIP',
                'metricName' => $metricName,
                'filter' => 'sum',
                'period' => 86400,
                'from' => $monthStart * 1000,
                'to' => $now * 1000,
            ];

            foreach ($dimensions as $index => $dimension) {
                $requestData['dim' . $index] = $dimension[0] . ',' . $dimension[1];
            }

            $response = $this->buildCesClient($account)->showMetricData(new ShowMetricDataRequest($requestData));
            $datapoints = $response->getDatapoints() ?? [];
            if (!empty($datapoints)) {
                $samples = [];
                foreach ($datapoints as $point) {
                    $samples[] = ['sum' => (float) $point->getSum()];
                }

                return array_sum(array_column($samples, 'sum'));
            }
        }

        return 0.0;
    }

    private function getInstanceMonthlyTrafficGb(array $account, string $instanceId): ?float
    {
        $incoming = $this->queryEcsRateTraffic($account, $instanceId, 'network_incoming_bytes_aggregate_rate');
        $outgoing = $this->queryEcsRateTraffic($account, $instanceId, 'network_outgoing_bytes_aggregate_rate');

        if ($incoming === null && $outgoing === null) {
            return null;
        }

        return round(($incoming ?? 0.0) + ($outgoing ?? 0.0), 3);
    }

    private function queryEcsRateTraffic(array $account, string $instanceId, string $metricName): ?float
    {
        $now = time();
        $monthStart = strtotime(date('Y-m-01 00:00:00', $now));
        $period = 3600;

        $response = $this->buildCesClient($account)->showMetricData(new ShowMetricDataRequest([
            'namespace' => 'SYS.ECS',
            'metricName' => $metricName,
            'dim0' => 'instance_id,' . $instanceId,
            'filter' => 'average',
            'period' => $period,
            'from' => $monthStart * 1000,
            'to' => $now * 1000,
        ]));

        $datapoints = $response->getDatapoints() ?? [];
        if (empty($datapoints)) {
            return null;
        }

        $samples = [];
        foreach ($datapoints as $point) {
            $samples[] = [
                'average' => (float) ($point->getAverage() ?? 0.0),
                'period' => $period,
            ];
        }

        return $this->buildTrafficFromRateDatapoints($samples);
    }

    private function discoverServerFromFallbackSecurityGroup(array $account): ?array
    {
        $securityGroupId = trim((string) ($account['security_group_id'] ?? ''));
        if ($securityGroupId === '') {
            return null;
        }

        $marker = null;
        $pageCount = 0;
        $matchedServers = [];

        do {
            $request = new ListCloudServersRequest([
                'limit' => 100,
                'marker' => $marker,
                'expectFields' => ['security_groups', 'addresses'],
            ]);

            $response = $this->buildEcsClient($account)->listCloudServers($request);
            $servers = [];
            foreach ($response->getServers() ?? [] as $server) {
                $servers[] = [
                    'id' => (string) $server->getId(),
                    'name' => (string) $server->getName(),
                    'status' => (string) $server->getStatus(),
                    'publicIp' => $this->extractServerPublicIp($server->getAddresses()),
                    'securityGroups' => $this->normalizeServerSecurityGroups($server->getSecurityGroups()),
                ];
            }

            foreach ($servers as $server) {
                foreach ($server['securityGroups'] ?? [] as $group) {
                    if (trim((string) ($group['id'] ?? '')) === $securityGroupId) {
                        $matchedServers[$server['id']] = $server;
                        break;
                    }
                }
            }

            $marker = null;
            $lastServer = end($servers);
            if (!empty($lastServer['id']) && count($servers) === 100) {
                $marker = $lastServer['id'];
            }

            $pageCount++;
        } while ($marker !== null && $pageCount < 10);

        if (count($matchedServers) === 1) {
            return reset($matchedServers);
        }

        return null;
    }

    private function loadSecurityGroupsFromServer(array $account, array $server): array
    {
        $securityGroups = [];
        foreach ($server['securityGroups'] as $group) {
            $groupId = trim((string) ($group['id'] ?? ''));
            if ($groupId === '') {
                continue;
            }

            $securityGroups[] = $this->loadSecurityGroupById($account, $groupId);
        }

        if (!empty($securityGroups)) {
            return $securityGroups;
        }

        if (!empty($account['security_group_id'])) {
            return [$this->loadSecurityGroupById($account, trim((string) $account['security_group_id']))];
        }

        throw new Exception('当前实例未返回可管理安全组');
    }

    private function loadSecurityGroupById(array $account, string $securityGroupId): array
    {
        $request = new ShowSecurityGroupRequest([
            'projectId' => $account['project_id'],
            'securityGroupId' => $securityGroupId,
        ]);

        $response = $this->buildVpcClient($account)->showSecurityGroup($request);
        $securityGroup = $response->getSecurityGroup();
        if (!$securityGroup) {
            throw new Exception('安全组不存在或当前 Project 下不可见');
        }

        return $this->formatSecurityGroup($securityGroup);
    }

    private function formatSecurityGroup($securityGroup): array
    {
        $rules = [];

        foreach ($securityGroup->getSecurityGroupRules() ?? [] as $rule) {
            if ($rule->getDirection() !== 'ingress') {
                continue;
            }

            $sourceDisplay = $rule->getRemoteIpPrefix() ?: '';
            if (!$sourceDisplay && $rule->getRemoteGroupId()) {
                $sourceDisplay = '安全组: ' . $rule->getRemoteGroupId();
            }
            if (!$sourceDisplay && $rule->getRemoteAddressGroupId()) {
                $sourceDisplay = '地址组: ' . $rule->getRemoteAddressGroupId();
            }
            if (!$sourceDisplay) {
                $sourceDisplay = '未识别来源';
            }

            $rules[] = [
                'security_group_rule_id' => $rule->getId(),
                'direction' => $rule->getDirection(),
                'ip_protocol' => strtoupper((string) ($rule->getProtocol() ?: 'ALL')),
                'port_range' => $rule->getMultiport() ?: '-1/-1',
                'source_cidr_ip' => $rule->getRemoteIpPrefix() ?: '',
                'ipv6_source_cidr_ip' => '',
                'source_group_id' => $rule->getRemoteGroupId() ?: '',
                'source_prefix_list_id' => '',
                'policy' => $rule->getAction() ?: 'allow',
                'priority' => $rule->getPriority(),
                'nic_type' => '',
                'description' => $rule->getDescription() ?: '',
                'source_display' => $sourceDisplay,
                'can_manage' => !empty($rule->getId()),
            ];
        }

        usort($rules, function ($a, $b) {
            return strcmp(
                $a['ip_protocol'] . '|' . $a['port_range'] . '|' . $a['source_display'],
                $b['ip_protocol'] . '|' . $b['port_range'] . '|' . $b['source_display']
            );
        });

        return [
            'security_group_id' => $securityGroup->getId(),
            'security_group_name' => $securityGroup->getName(),
            'description' => $securityGroup->getDescription(),
            'vpc_id' => '',
            'rules' => $rules,
        ];
    }

    private function buildIncompleteContext(array $account, string $message): array
    {
        return [
            'instanceId' => $account['instance_id'] ?? '',
            'instanceName' => '',
            'instanceStatus' => 'Unknown',
            'publicIp' => '',
            'securityGroups' => [],
            'securityGroupCount' => 0,
            'securityGroupNames' => [],
            'discoveryStatus' => 'incomplete',
            'discoveryMode' => 'incomplete',
            'discoveryMessage' => $message,
            'usingFallbackSecurityGroup' => false,
            'fallbackSecurityGroupId' => trim((string) ($account['security_group_id'] ?? '')),
            'trafficDataAvailable' => false,
            'trafficUsedGb' => null,
            'trafficError' => '',
        ];
    }

    private function buildFallbackContext(array $account, string $reason): array
    {
        $fallbackSecurityGroupId = trim((string) ($account['security_group_id'] ?? ''));
        if ($fallbackSecurityGroupId === '') {
            return [
                'instanceId' => $account['instance_id'] ?? '',
                'instanceName' => '',
                'instanceStatus' => 'Unknown',
                'publicIp' => '',
                'securityGroups' => [],
                'securityGroupCount' => 0,
                'securityGroupNames' => [],
                'discoveryStatus' => empty($account['instance_id']) ? 'incomplete' : 'error',
                'discoveryMode' => 'security_group_fallback',
                'discoveryMessage' => $reason,
                'usingFallbackSecurityGroup' => false,
                'fallbackSecurityGroupId' => '',
                'trafficDataAvailable' => false,
                'trafficUsedGb' => null,
                'trafficError' => '',
            ];
        }

        try {
            $group = $this->loadSecurityGroupById($account, $fallbackSecurityGroupId);
            return [
                'instanceId' => $account['instance_id'] ?? '',
                'instanceName' => '',
                'instanceStatus' => 'Unknown',
                'publicIp' => '',
                'securityGroups' => [$group],
                'securityGroupCount' => 1,
                'securityGroupNames' => [$group['security_group_name'] ?: $fallbackSecurityGroupId],
                'discoveryStatus' => 'fallback',
                'discoveryMode' => 'security_group_fallback',
                'discoveryMessage' => $reason,
                'usingFallbackSecurityGroup' => true,
                'fallbackSecurityGroupId' => $fallbackSecurityGroupId,
                'trafficDataAvailable' => false,
                'trafficUsedGb' => null,
                'trafficError' => '',
            ];
        } catch (\Throwable $e) {
            return [
                'instanceId' => $account['instance_id'] ?? '',
                'instanceName' => '',
                'instanceStatus' => 'Unknown',
                'publicIp' => '',
                'securityGroups' => [],
                'securityGroupCount' => 0,
                'securityGroupNames' => [],
                'discoveryStatus' => empty($account['instance_id']) ? 'incomplete' : 'error',
                'discoveryMode' => 'security_group_fallback',
                'discoveryMessage' => $reason . '；兼容安全组也不可用：' . $this->formatThrowableMessage($e),
                'usingFallbackSecurityGroup' => false,
                'fallbackSecurityGroupId' => $fallbackSecurityGroupId,
                'trafficDataAvailable' => false,
                'trafficUsedGb' => null,
                'trafficError' => '',
            ];
        }
    }

    private function enrichContextWithTraffic(array $account, array $context): array
    {
        try {
            $context['trafficUsedGb'] = $this->getTraffic([
                ...$account,
                'instance_id' => $context['instanceId'] ?? ($account['instance_id'] ?? ''),
            ]);
            $context['trafficDataAvailable'] = true;
            $context['trafficError'] = '';
        } catch (\Throwable $e) {
            $context['trafficDataAvailable'] = false;
            $context['trafficUsedGb'] = null;
            $context['trafficError'] = $this->formatThrowableMessage($e);
        }

        return $context;
    }

    private function getMissingBaseFields(array $account): array
    {
        $missing = [];

        if (empty($account['access_key_id']) || empty($account['access_key_secret'])) {
            $missing[] = 'AK/SK';
        }
        if (empty($account['region_id'])) {
            $missing[] = 'Region ID';
        }
        if (empty($account['project_id'])) {
            $missing[] = 'Project ID';
        }

        return $missing;
    }

    private function normalizeServerSecurityGroups($groups): array
    {
        $normalized = [];
        foreach ($groups ?? [] as $group) {
            if (is_array($group)) {
                $normalized[] = [
                    'id' => $group['id'] ?? '',
                    'name' => $group['name'] ?? ($group['id'] ?? ''),
                ];
                continue;
            }

            $normalized[] = [
                'id' => method_exists($group, 'getId') ? (string) $group->getId() : '',
                'name' => method_exists($group, 'getName') ? (string) $group->getName() : '',
            ];
        }

        return array_values(array_filter($normalized, function ($group) {
            return trim((string) ($group['id'] ?? '')) !== '';
        }));
    }

    private function extractServerPublicIp($addresses): string
    {
        if (!is_array($addresses)) {
            return '';
        }

        foreach ($addresses as $networkAddresses) {
            foreach ($networkAddresses ?? [] as $address) {
                $type = is_array($address)
                    ? (string) ($address['OS-EXT-IPS:type'] ?? '')
                    : (method_exists($address, 'getOsExtIpStype') ? (string) $address->getOsExtIpStype() : '');

                $version = is_array($address)
                    ? (int) ($address['version'] ?? 0)
                    : (method_exists($address, 'getVersion') ? (int) $address->getVersion() : 0);

                $ip = is_array($address)
                    ? (string) ($address['addr'] ?? '')
                    : (method_exists($address, 'getAddr') ? (string) $address->getAddr() : '');

                if ($type === 'floating' && $version === 4 && $ip !== '') {
                    return $ip;
                }
            }
        }

        foreach ($addresses as $networkAddresses) {
            foreach ($networkAddresses ?? [] as $address) {
                $version = is_array($address)
                    ? (int) ($address['version'] ?? 0)
                    : (method_exists($address, 'getVersion') ? (int) $address->getVersion() : 0);

                $ip = is_array($address)
                    ? (string) ($address['addr'] ?? '')
                    : (method_exists($address, 'getAddr') ? (string) $address->getAddr() : '');

                if ($version === 4 && $ip !== '') {
                    return $ip;
                }
            }
        }

        return '';
    }

    private function mapHuaweiStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'ACTIVE' => 'Running',
            'SHUTOFF' => 'Stopped',
            'BUILD', 'REBOOT', 'HARD_REBOOT', 'MIGRATING', 'REBUILD', 'RESIZE', 'VERIFY_RESIZE' => 'Starting',
            default => 'Unknown',
        };
    }

    private function formatThrowableMessage(\Throwable $e): string
    {
        $message = trim((string) $e->getMessage());
        return $message !== '' ? $message : '华为云请求失败';
    }
}
