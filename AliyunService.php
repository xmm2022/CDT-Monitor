<?php

use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class AliyunService
{
    private function getAccountProxyCacheKey($account)
    {
        return implode('|', [
            $account['access_key_id'] ?? '',
            $account['region_id'] ?? '',
            $account['instance_id'] ?? '',
            $account['site_type'] ?? '',
            $account['api_proxy_enabled'] ?? 0,
            $account['api_proxy_host'] ?? '',
            $account['api_proxy_port'] ?? '',
            $account['api_proxy_user'] ?? '',
            $account['api_proxy_pass'] ?? ''
        ]);
    }

    private function buildProxyOptions($account)
    {
        if (($account['api_proxy_enabled'] ?? 0) != 1) {
            return [];
        }

        $host = trim((string) ($account['api_proxy_host'] ?? ''));
        $port = trim((string) ($account['api_proxy_port'] ?? ''));
        $username = (string) ($account['api_proxy_user'] ?? '');
        $password = (string) ($account['api_proxy_pass'] ?? '');

        if ($host === '' || $port === '') {
            throw new \Exception('SOCKS5 代理未完整配置');
        }

        if (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            throw new \Exception('SOCKS5 代理端口无效');
        }

        $curlOptions = [
            CURLOPT_PROXY => $host . ':' . $port,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
        ];

        if ($username !== '' || $password !== '') {
            $curlOptions[CURLOPT_PROXYUSERPWD] = $username . ':' . $password;
        }

        return [
            'curl' => $curlOptions,
        ];
    }

    private function buildRequestOptions($account, $query = [], $timeout = 10.0)
    {
        $options = [
            'connect_timeout' => 5.0,
            'timeout' => $timeout
        ];

        if (!empty($query)) {
            $options['query'] = $query;
        }

        return array_replace_recursive($options, $this->buildProxyOptions($account));
    }

    private function initAccessKeyClient($key, $secret, $regionId)
    {
        AlibabaCloud::accessKeyClient($key, $secret)
            ->regionId($regionId)
            ->asDefaultClient();
    }

    private function normalizeApiList($value)
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            return [$value];
        }

        if (empty($value)) {
            return [];
        }

        $keys = array_keys($value);
        $isSequential = $keys === range(0, count($value) - 1);
        return $isSequential ? $value : [$value];
    }

    private function initEcsClient($account)
    {
        $this->initAccessKeyClient($account['access_key_id'], $account['access_key_secret'], $account['region_id']);
    }

    private function requestEcs($account, $action, $query = [], $timeout = 10.0)
    {
        $this->initEcsClient($account);

        return AlibabaCloud::rpc()
            ->product('Ecs')
            ->scheme('https')
            ->version('2014-05-26')
            ->action($action)
            ->method('POST')
            ->host("ecs.{$account['region_id']}.aliyuncs.com")
            ->options($this->buildRequestOptions($account, $query, $timeout))
            ->request();
    }

    /**
     * 智能重试执行器
     * 自动处理网络抖动、超时和服务端临时错误
     * * @param callable $func 业务逻辑闭包
     * @param string $action 操作名称
     * @param int $maxRetries 最大重试次数
     * @return mixed
     * @throws \Exception
     */
    private function executeWithRetry(callable $func, $action, $maxRetries = 3) // 优化点1: 将默认重试次数回调为 3 次，平衡前端等待体验
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $func();
            } catch (ClientException $e) {
                // 客户端错误(4xx)通常不重试，除非是流控限制(Throttling)
                $errorCode = $e->getErrorCode();
                if (stripos($errorCode, 'Throttling') !== false) {
                    $lastException = $e;
                    // 流控触发时，等待时间稍长
                    $this->backoff($attempt, true);
                    $attempt++;
                    continue;
                }
                throw $e; // 其他 4xx 错误直接抛出（如 AccessKey 错误）
            } catch (ServerException $e) {
                // 服务端错误(5xx)需要重试
                $lastException = $e;
            } catch (\Exception $e) {
                // 网络/cURL错误(超时、无法解析DNS等)需要重试
                $lastException = $e;
            }

            $attempt++;
            if ($attempt < $maxRetries) {
                // 记录简短日志到标准输出（可选，方便调试 Docker logs）
                // echo "Warning: Retrying $action (Attempt $attempt/$maxRetries)...\n";
                $this->backoff($attempt);
            }
        }

        throw $lastException;
    }

    /**
     * 指数退避策略
     * @param int $attempt 当前尝试次数
     * @param bool $isThrottling 是否因为流控
     */
    private function backoff($attempt, $isThrottling = false)
    {
        // 优化点2: 基础等待时间从 0.5s 提升至 1s
        // 序列变为: 1s, 2s, 4s... 3次重试总耗时控制在合理范围内
        $base = 1000000 * pow(2, $attempt);
        if ($isThrottling) {
            $base *= 2; // 流控时等待时间翻倍
        }
        // 增加随机抖动，避免多线程/多容器并发请求撞车
        $jitter = rand(0, 500000);
        usleep($base + $jitter);
    }

    private $trafficCache = [];

    /**
     * 判断是否为海外区域
     * 国内区域：cn-* (排除 cn-hongkong)
     * 海外区域：其他所有区域 + cn-hongkong
     */
    private function isOverseas($regionId)
    {
        // 简单判断：如果以 cn- 开头且不是 cn-hongkong，则是国内
        if (strpos($regionId, 'cn-') === 0 && $regionId !== 'cn-hongkong') {
            return false;
        }
        return true;
    }

    /**
     * 获取 BSS 费用中心 API 的 regionId 和 endpoint
     * 中国站: cn-hangzhou + business.aliyuncs.com
     * 国际站: ap-southeast-1 + business.ap-southeast-1.aliyuncs.com
     * @param string $siteType 'china' 或 'international'
     */
    private function getBssEndpoint($siteType = 'china')
    {
        if ($siteType === 'international') {
            return [
                'regionId' => 'ap-southeast-1',
                'host'     => 'business.ap-southeast-1.aliyuncs.com'
            ];
        }
        return [
            'regionId' => 'cn-hangzhou',
            'host'     => 'business.aliyuncs.com'
        ];
    }

    /**
     * 获取 CDT 流量
     * @param array $account 账户配置
     * @throws \Exception
     */
    public function getTraffic($account)
    {
        // 1. 检查缓存
        $cacheKey = md5($this->getAccountProxyCacheKey($account));
        if (isset($this->trafficCache[$cacheKey])) {
            $result = $this->trafficCache[$cacheKey];
        } else {
            // 2. 如果无缓存，发起 API 请求
            $result = $this->executeWithRetry(function () use ($account) {
                $this->initAccessKeyClient($account['access_key_id'], $account['access_key_secret'], 'cn-hongkong');

                return AlibabaCloud::rpc()
                    ->product('CDT')
                    ->scheme('https')
                    ->version('2021-08-13')
                    ->action('ListCdtInternetTraffic')
                    ->method('POST')
                    ->host('cdt.aliyuncs.com')
                    ->options($this->buildRequestOptions($account, [], 10.0))
                    ->request();
            }, 'getTraffic');

            // 写入缓存
            $this->trafficCache[$cacheKey] = $result;
        }

        if (isset($result['TrafficDetails'])) {
            $isTargetOverseas = $this->isOverseas($account['region_id']);
            $totalTraffic = 0;

            foreach ($result['TrafficDetails'] as $detail) {
                // 核心逻辑：区分国内/海外
                // 只有当流量产生区域的属性（国内/海外）与目标实例区域属性一致时，才计入
                $trafficRegion = $detail['BusinessRegionId'] ?? '';
                if ($this->isOverseas($trafficRegion) === $isTargetOverseas) {
                    $totalTraffic += $detail['Traffic'];
                }
            }

            return $totalTraffic / (1024 * 1024 * 1024);
        }

        throw new \Exception("API 响应缺少 TrafficDetails 字段");
    }

    /**
     * 获取实例状态
     * @throws \Exception
     */
    public function getInstanceStatus($account)
    {
        return $this->executeWithRetry(function () use ($account) {
            $query = ['RegionId' => $account['region_id']];

            if (!empty($account['instance_id'])) {
                $query['InstanceId'] = $account['instance_id'];
            }

            $result = $this->requestEcs($account, 'DescribeInstanceStatus', $query, 10.0);

            if (isset($result['InstanceStatuses']['InstanceStatus'][0]['Status'])) {
                return $result['InstanceStatuses']['InstanceStatus'][0]['Status'];
            }

            throw new \Exception("API 响应未找到实例状态 (请检查 Instance ID)");
        }, 'getInstanceStatus');
    }

    /**
     * 控制实例开关机
     * @throws \Exception
     */
    public function controlInstance($account, $action, $shutdownMode = 'KeepCharging')
    {
        return $this->executeWithRetry(function () use ($account, $action, $shutdownMode) {
            if (empty($account['instance_id'])) {
                throw new \Exception("未配置 Instance ID");
            }

            $query = [
                'RegionId' => $account['region_id'],
                'InstanceId' => $account['instance_id']
            ];

            if ($action === 'stop') {
                $query['StoppedMode'] = $shutdownMode;
            }

            $this->requestEcs($account, $action === 'stop' ? 'StopInstance' : 'StartInstance', $query, 10.0);

            return true;
        }, 'controlInstance');
    }

    public function getInstanceSecurityGroups($account)
    {
        return $this->executeWithRetry(function () use ($account) {
            if (empty($account['instance_id'])) {
                throw new \Exception("未配置 Instance ID");
            }

            $instance = $this->requestEcs($account, 'DescribeInstanceAttribute', [
                'RegionId' => $account['region_id'],
                'InstanceId' => $account['instance_id']
            ], 10.0);

            $securityGroupIds = $this->normalizeApiList($instance['SecurityGroupIds']['SecurityGroupId'] ?? []);
            $groups = [];

            foreach ($securityGroupIds as $securityGroupId) {
                $detail = $this->requestEcs($account, 'DescribeSecurityGroupAttribute', [
                    'RegionId' => $account['region_id'],
                    'SecurityGroupId' => $securityGroupId,
                    'NicType' => 'intranet',
                    'Direction' => 'ingress',
                    'MaxResults' => 1000
                ], 10.0);

                $permissions = $this->normalizeApiList($detail['Permissions']['Permission'] ?? []);
                $rules = [];

                foreach ($permissions as $permission) {
                    $sourceDisplay = $permission['SourceCidrIp'] ?? '';
                    if (!$sourceDisplay && !empty($permission['Ipv6SourceCidrIp'])) {
                        $sourceDisplay = $permission['Ipv6SourceCidrIp'];
                    }
                    if (!$sourceDisplay && !empty($permission['SourceGroupId'])) {
                        $sourceDisplay = '安全组: ' . $permission['SourceGroupId'];
                    }
                    if (!$sourceDisplay && !empty($permission['SourcePrefixListId'])) {
                        $sourceDisplay = '前缀列表: ' . $permission['SourcePrefixListId'];
                    }
                    if (!$sourceDisplay) {
                        $sourceDisplay = '未识别来源';
                    }

                    $rules[] = [
                        'security_group_rule_id' => $permission['SecurityGroupRuleId'] ?? '',
                        'direction' => $permission['Direction'] ?? 'ingress',
                        'ip_protocol' => strtoupper($permission['IpProtocol'] ?? ''),
                        'port_range' => $permission['PortRange'] ?? '',
                        'source_cidr_ip' => $permission['SourceCidrIp'] ?? '',
                        'ipv6_source_cidr_ip' => $permission['Ipv6SourceCidrIp'] ?? '',
                        'source_group_id' => $permission['SourceGroupId'] ?? '',
                        'source_prefix_list_id' => $permission['SourcePrefixListId'] ?? '',
                        'policy' => $permission['Policy'] ?? '',
                        'priority' => $permission['Priority'] ?? '',
                        'nic_type' => $permission['NicType'] ?? '',
                        'description' => $permission['Description'] ?? '',
                        'source_display' => $sourceDisplay,
                        'can_manage' => !empty($permission['SecurityGroupRuleId']) || !empty($permission['SourceCidrIp']) || !empty($permission['SourceGroupId']) || !empty($permission['SourcePrefixListId'])
                    ];
                }

                usort($rules, function ($a, $b) {
                    return strcmp(
                        $a['ip_protocol'] . '|' . $a['port_range'] . '|' . $a['source_display'],
                        $b['ip_protocol'] . '|' . $b['port_range'] . '|' . $b['source_display']
                    );
                });

                $groups[] = [
                    'security_group_id' => $securityGroupId,
                    'security_group_name' => $detail['SecurityGroupName'] ?? $securityGroupId,
                    'description' => $detail['Description'] ?? '',
                    'vpc_id' => $detail['VpcId'] ?? '',
                    'rules' => $rules
                ];
            }

            return $groups;
        }, 'getInstanceSecurityGroups');
    }

    public function addSecurityGroupRule($account, $securityGroupId, $rule)
    {
        return $this->executeWithRetry(function () use ($account, $securityGroupId, $rule) {
            if (empty($securityGroupId)) {
                throw new \Exception("缺少安全组 ID");
            }

            $query = [
                'RegionId' => $account['region_id'],
                'SecurityGroupId' => $securityGroupId,
                'Permissions.1.IpProtocol' => $rule['ip_protocol'],
                'Permissions.1.PortRange' => $rule['port_range'],
                'Permissions.1.SourceCidrIp' => $rule['source_cidr_ip'],
                'Permissions.1.Policy' => 'accept',
                'Permissions.1.Priority' => '1',
                'Permissions.1.NicType' => 'intranet'
            ];

            if (!empty($rule['description'])) {
                $query['Permissions.1.Description'] = $rule['description'];
            }

            $this->requestEcs($account, 'AuthorizeSecurityGroup', $query, 10.0);
            return true;
        }, 'addSecurityGroupRule');
    }

    public function deleteSecurityGroupRule($account, $securityGroupId, $rule)
    {
        return $this->executeWithRetry(function () use ($account, $securityGroupId, $rule) {
            if (empty($securityGroupId)) {
                throw new \Exception("缺少安全组 ID");
            }

            $query = [
                'RegionId' => $account['region_id'],
                'SecurityGroupId' => $securityGroupId
            ];

            if (!empty($rule['security_group_rule_id'])) {
                $query['SecurityGroupRuleId.1'] = $rule['security_group_rule_id'];
            } else {
                $query['Permissions.1.IpProtocol'] = strtoupper($rule['ip_protocol'] ?? '');
                $query['Permissions.1.PortRange'] = $rule['port_range'] ?? '';
                $query['Permissions.1.Policy'] = strtolower($rule['policy'] ?? 'accept');
                $query['Permissions.1.NicType'] = $rule['nic_type'] ?? 'intranet';

                if (!empty($rule['source_cidr_ip'])) {
                    $query['Permissions.1.SourceCidrIp'] = $rule['source_cidr_ip'];
                } elseif (!empty($rule['source_group_id'])) {
                    $query['Permissions.1.SourceGroupId'] = $rule['source_group_id'];
                } elseif (!empty($rule['source_prefix_list_id'])) {
                    $query['Permissions.1.SourcePrefixListId'] = $rule['source_prefix_list_id'];
                } else {
                    throw new \Exception("缺少可删除的规则标识");
                }
            }

            $this->requestEcs($account, 'RevokeSecurityGroup', $query, 10.0);
            return true;
        }, 'deleteSecurityGroupRule');
    }

    // ==================== BSS 费用中心 API ====================

    private $balanceCache = [];

    /**
     * 查询账户可用余额
     * @param array $account 账户配置
     * @return array ['AvailableAmount' => '...', 'Currency' => 'CNY']
     * @throws \Exception
     */
    public function getAccountBalance($account)
    {
        $cacheKey = md5($this->getAccountProxyCacheKey($account) . '|balance');
        if (isset($this->balanceCache[$cacheKey])) {
            return $this->balanceCache[$cacheKey];
        }

        $bss = $this->getBssEndpoint($account['site_type'] ?? 'china');

        $result = $this->executeWithRetry(function () use ($account, $bss) {
            $this->initAccessKeyClient($account['access_key_id'], $account['access_key_secret'], $bss['regionId']);

            return AlibabaCloud::rpc()
                ->product('BssOpenApi')
                ->scheme('https')
                ->version('2017-12-14')
                ->action('QueryAccountBalance')
                ->method('POST')
                ->host($bss['host'])
                ->options($this->buildRequestOptions($account, [], 10.0))
                ->request();
        }, 'getAccountBalance');

        $data = [
            'AvailableAmount' => $result['Data']['AvailableAmount'] ?? '0',
            'Currency' => $result['Data']['Currency'] ?? 'CNY'
        ];

        $this->balanceCache[$cacheKey] = $data;
        return $data;
    }

    /**
     * 查询指定实例的当月账单明细
     * @param array $account 账户配置
     * @param string $billingCycle 账期 (格式: 2026-03)
     * @return array ['TotalCost' => float, 'Items' => [...]]
     * @throws \Exception
     */
    public function getInstanceBill($account, $billingCycle)
    {
        $bss = $this->getBssEndpoint($account['site_type'] ?? 'china');

        $result = $this->executeWithRetry(function () use ($account, $billingCycle, $bss) {
            $this->initAccessKeyClient($account['access_key_id'], $account['access_key_secret'], $bss['regionId']);

            return AlibabaCloud::rpc()
                ->product('BssOpenApi')
                ->scheme('https')
                ->version('2017-12-14')
                ->action('DescribeInstanceBill')
                ->method('POST')
                ->host($bss['host'])
                ->options($this->buildRequestOptions($account, [
                    'BillingCycle' => $billingCycle,
                    'InstanceID' => $account['instance_id'],
                    'Granularity' => 'MONTHLY'
                ], 15.0))
                ->request();
        }, 'getInstanceBill');

        $items = $result['Data']['Items'] ?? [];
        $totalCost = 0;
        $details = [];

        foreach ($items as $item) {
            $cost = (float) ($item['PretaxAmount'] ?? 0);
            $totalCost += $cost;
            $details[] = [
                'ProductName' => $item['ProductName'] ?? '',
                'ProductCode' => $item['ProductCode'] ?? '',
                'BillingType' => $item['BillingType'] ?? '',
                'PretaxAmount' => $cost,
                'DeductedByCashCoupons' => (float) ($item['DeductedByCashCoupons'] ?? 0),
                'DeductedByPrepaidCard' => (float) ($item['DeductedByPrepaidCard'] ?? 0),
                'PaymentAmount' => (float) ($item['PaymentAmount'] ?? 0),
            ];
        }

        return [
            'TotalCost' => round($totalCost, 2),
            'Items' => $details
        ];
    }

    /**
     * 查询账单总览 (按产品分类的月度费用)
     * @param array $account 账户配置
     * @param string $billingCycle 账期 (格式: 2026-03)
     * @return array ['TotalCost' => float, 'Products' => [...]]
     * @throws \Exception
     */
    public function getBillOverview($account, $billingCycle)
    {
        $bss = $this->getBssEndpoint($account['site_type'] ?? 'china');

        $result = $this->executeWithRetry(function () use ($account, $billingCycle, $bss) {
            $this->initAccessKeyClient($account['access_key_id'], $account['access_key_secret'], $bss['regionId']);

            return AlibabaCloud::rpc()
                ->product('BssOpenApi')
                ->scheme('https')
                ->version('2017-12-14')
                ->action('QueryBillOverview')
                ->method('POST')
                ->host($bss['host'])
                ->options($this->buildRequestOptions($account, [
                    'BillingCycle' => $billingCycle
                ], 15.0))
                ->request();
        }, 'getBillOverview');

        $items = $result['Data']['Items']['Item'] ?? [];
        $totalCost = 0;
        $products = [];

        foreach ($items as $item) {
            $cost = (float) ($item['PretaxAmount'] ?? 0);
            if ($cost <= 0) continue;
            $totalCost += $cost;
            $products[] = [
                'ProductName' => $item['ProductName'] ?? '',
                'ProductCode' => $item['ProductCode'] ?? '',
                'PretaxAmount' => round($cost, 2),
                'PaymentAmount' => round((float) ($item['PaymentAmount'] ?? 0), 2)
            ];
        }

        // 按费用降序排列
        usort($products, function ($a, $b) {
            return $b['PretaxAmount'] <=> $a['PretaxAmount'];
        });

        return [
            'TotalCost' => round($totalCost, 2),
            'Products' => $products
        ];
    }
}
