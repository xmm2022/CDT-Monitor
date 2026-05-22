<?php

require 'vendor/autoload.php';
require_once 'Database.php';
require_once 'ConfigManager.php';
require_once 'AliyunService.php';
require_once 'NotificationService.php';
require_once __DIR__ . '/providers/ProviderFactory.php';

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;
use HuaweiCloud\SDK\Core\Exceptions\ClientRequestException as HuaweiClientRequestException;

class AliyunTrafficCheck
{
    private $db;
    private $configManager;
    private $providerFactory;
    private $notificationService;
    private $initError = null;



    public function __construct()
    {
        try {
            $this->db = new Database();
            $this->configManager = new ConfigManager($this->db);
            $this->providerFactory = new ProviderFactory(new AliyunService());
            $this->notificationService = new NotificationService();

            // 注入配置到通知服务
            $this->notificationService->setConfig($this->configManager->getAllSettings());
        } catch (Exception $e) {
            $this->initError = $e->getMessage();
        }
    }

    public function getInitError()
    {
        return $this->initError;
    }

    public function isInitialized()
    {
        if ($this->initError)
            return false;
        return $this->configManager->isInitialized();
    }

    public function getAdminPassword()
    {
        return $this->configManager->get('admin_password', '');
    }

    public function login($password)
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        }

        $attempts = $this->db->getRecentFailedAttempts($ip, 900);
        if ($attempts >= 5) {
            $this->db->addLog('warning', "登录被锁定: IP {$ip} 尝试次数过多");
            throw new Exception("错误次数过多，请 15 分钟后再试。");
        }

        $adminPass = $this->getAdminPassword();
        if (empty($adminPass))
            return false;

        if (hash_equals((string) $adminPass, (string) $password)) {
            $this->db->clearLoginAttempts($ip);
            $this->db->addLog('info', "管理员登录成功 [IP: {$ip}]");
            return true;
        }

        $this->db->recordLoginAttempt($ip);
        $this->db->addLog('warning', "管理员登录失败 [IP: {$ip}]");
        return false;
    }

    public function setup($data)
    {
        if ($this->initError)
            throw new Exception($this->initError);
        if ($this->isInitialized())
            return false;
        return $this->configManager->updateConfig($data);
    }

    public function updateConfig($data)
    {
        $success = $this->configManager->updateConfig($data);
        if ($success) {
            $this->notificationService->setConfig($this->configManager->getAllSettings());
        }
        return $success;
    }

    public function getLastConfigError()
    {
        return $this->configManager ? $this->configManager->getLastError() : '';
    }

    public function getConfigForFrontend()
    {
        if ($this->initError)
            return [];

        $settings = $this->configManager->getAllSettings();
        $accounts = $this->configManager->getAccounts();

        $config = [
            'admin_password' => $settings['admin_password'] ?? '',
            'traffic_threshold' => (int) ($settings['traffic_threshold'] ?? 95),
            'enable_schedule_email' => ($settings['enable_schedule_email'] ?? '0') === '1',
            'shutdown_mode' => $settings['shutdown_mode'] ?? 'KeepCharging',
            'threshold_action' => $settings['threshold_action'] ?? 'stop_and_notify',
            'keep_alive' => ($settings['keep_alive'] ?? '0') === '1',
            'api_interval' => (int) ($settings['api_interval'] ?? 600),
            'enable_billing' => ($settings['enable_billing'] ?? '0') === '1',
            'Notification' => [
                'email_enabled' => ($settings['notify_email_enabled'] ?? '1') === '1',
                'email' => $settings['notify_email'] ?? '',
                'host' => $settings['notify_host'] ?? '',
                'port' => $settings['notify_port'] ?? 465,
                'username' => $settings['notify_username'] ?? '',
                'password' => $settings['notify_password'] ?? '',
                'secure' => $settings['notify_secure'] ?? 'ssl',
                'telegram' => [
                    'enabled' => ($settings['notify_tg_enabled'] ?? '0') === '1',
                    'token' => $settings['notify_tg_token'] ?? '',
                    'chat_id' => $settings['notify_tg_chat_id'] ?? '',
                    'proxy_type' => $settings['notify_tg_proxy_type'] ?? 'none',
                    'proxy_url' => $settings['notify_tg_proxy_url'] ?? '',
                    'proxy_ip' => $settings['notify_tg_proxy_ip'] ?? '',
                    'proxy_port' => $settings['notify_tg_proxy_port'] ?? '',
                    'proxy_user' => $settings['notify_tg_proxy_user'] ?? '',
                    'proxy_pass' => $settings['notify_tg_proxy_pass'] ?? ''
                ],
                'webhook' => [
                    'enabled' => ($settings['notify_wh_enabled'] ?? '0') === '1',
                    'url' => $settings['notify_wh_url'] ?? '',
                    'method' => $settings['notify_wh_method'] ?? 'GET',
                    'request_type' => $settings['notify_wh_request_type'] ?? 'JSON',
                    'headers' => $settings['notify_wh_headers'] ?? '',
                    'body' => $settings['notify_wh_body'] ?? ''
                ]
            ],
            'Accounts' => []
        ];

        foreach ($accounts as $row) {
            $provider = $this->getProviderForAccount($row);
            $accountConfig = [
                'cloudProvider' => $row['cloud_provider'] ?? 'aliyun',
                'capabilities' => $provider->getCapabilities($row),
                'AccessKeyId' => $row['access_key_id'],
                'AccessKeySecret' => $row['access_key_secret'],
                'regionId' => $row['region_id'],
                'instanceId' => $row['instance_id'],
                'projectId' => $row['project_id'] ?? '',
                'securityGroupId' => $row['security_group_id'] ?? '',
                'maxTraffic' => (float) $row['max_traffic'],
                'schedule' => [
                    'enabled' => $row['schedule_enabled'] == 1,
                    'startTime' => $row['start_time'],
                    'stopTime' => $row['stop_time']
                ],
                'remark' => $row['remark'] ?? '',
                'siteType' => $row['site_type'] ?? 'china',
                'apiProxy' => [
                    'enabled' => ($row['api_proxy_enabled'] ?? 0) == 1,
                    'host' => $row['api_proxy_host'] ?? '',
                    'port' => $row['api_proxy_port'] ?? '',
                    'username' => $row['api_proxy_user'] ?? '',
                    'password' => $row['api_proxy_pass'] ?? ''
                ]
            ];

            if ($this->providerSupportsInstanceContext($provider, $row) && empty($row['instance_id'])) {
                $context = $this->safeDescribeAccountContext($provider, $row);
                if (!empty($context['instanceId'])) {
                    $accountConfig['suggestedInstanceId'] = $context['instanceId'];
                    $accountConfig['suggestedInstanceName'] = $context['instanceName'] ?? '';
                    $accountConfig['suggestedPublicIp'] = $context['publicIp'] ?? '';
                }
            }

            $config['Accounts'][] = $accountConfig;
        }

        return $config;
    }

    // --- 修改：支持按 Tab 获取日志 ---
    public function getSystemLogs($tab = 'action')
    {
        if ($this->initError)
            return [];

        if ($tab === 'heartbeat') {
            // 心跳日志：只看 heartbeat 类型
            $types = ['heartbeat'];
        } else {
            // 动作日志：只看 info 和 warning，排除 error (超时/接口错误)
            $types = ['info', 'warning'];
        }

        // 仅返回最近 20 条
        $logs = $this->db->getLogsByTypes($types, 20);

        foreach ($logs as &$log) {
            $log['time_str'] = date('Y-m-d H:i:s', $log['created_at']);
        }
        return $logs;
    }

    // --- 新增：清空日志并重排 ID ---
    public function clearSystemLogs($tab = 'action')
    {
        if ($this->initError)
            return false;

        $result = false;
        if ($tab === 'heartbeat') {
            $result = $this->db->clearLogsByTypes(['heartbeat']);
        } else {
            $result = $this->db->clearLogsByTypes(['info', 'warning', 'error']);
        }

        // 关键改动：清空后立即重排剩余 ID
        if ($result) {
            $this->db->reorderLogsIds();
        }

        return $result;
    }

    public function getAccountHistory($id)
    {
        if ($this->initError)
            return [];

        $account = $this->configManager->getAccountById($id);
        if (!$account)
            return ['error' => 'Account not found'];

        if (!$account)
            return ['error' => 'Account not found'];

        // Use account ID for stats query
        $rawHourly = $this->db->getHourlyStats($id);
        $chartHourly = [];
        foreach ($rawHourly as $row) {
            $chartHourly[] = [
                'time' => date('H:00', $row['recorded_at']),
                'full_time' => date('Y-m-d H:i', $row['recorded_at']),
                'value' => round($row['traffic'], 3)
            ];
        }

        $rawDaily = $this->db->getDailyStats($id);
        $chartDaily = [];
        foreach ($rawDaily as $row) {
            $chartDaily[] = [
                'date' => date('Y-m-d', $row['recorded_at']),
                'value' => round($row['traffic'], 3)
            ];
        }

        return [
            'history_24h' => $chartHourly,
            'history_30d' => $chartDaily
        ];
    }

    // --- 核心监控逻辑 ---

    public function monitor()
    {
        if ($this->initError)
            return "Error: " . $this->initError;

        // 优化：分级清理日志
        // 普通/重要日志保留 30 天，高频心跳日志仅保留 3 天
        $this->db->pruneLogs(30, 3);

        // 关键改动：每次清理后重排 ID，保证 ID 永远紧凑
        $this->db->reorderLogsIds();

        $this->db->pruneStats();

        // 优化：每天凌晨 04:xx 执行一次 VACUUM 整理数据库碎片
        if (date('H') === '04' && date('i') === '00') {
            $this->db->vacuum();
        }

        $logs = [];
        $currentUserTime = date('H:i');
        $currentTime = time();

        $threshold = (int) $this->configManager->get('traffic_threshold', 95);
        $shutdownMode = $this->configManager->get('shutdown_mode', 'KeepCharging');
        $thresholdAction = $this->configManager->get('threshold_action', 'stop_and_notify');
        $keepAlive = $this->configManager->get('keep_alive', '0') === '1';
        $userInterval = (int) $this->configManager->get('api_interval', 600);

        $accounts = $this->configManager->getAccounts();

        foreach ($accounts as $account) {
            $provider = $this->getProviderForAccount($account);
            $capabilities = $provider->getCapabilities($account);
            $context = $this->providerSupportsInstanceContext($provider, $account)
                ? $this->safeDescribeAccountContext($provider, $account)
                : null;
            $canTrafficMonitor = !empty($capabilities['traffic_monitor']) && ($context === null || !empty($context['trafficDataAvailable']));
            $canInstanceControl = !empty($capabilities['instance_start_stop']);
            $canScheduleManage = !empty($capabilities['schedule_manage']) && $canInstanceControl;
            $logPrefix = "[{$account['access_key_id']}]";
            $actions = [];
            $forceRefresh = false;
            $statusTransformed = false;

            // 1. 定时任务
            if ($canScheduleManage && $account['schedule_enabled'] == 1) {
                if ($account['start_time'] && $currentUserTime === $account['start_time']) {
                    if ($this->safeControlInstance($account, 'start')) {
                        $actions[] = "定时启动";
                        $this->db->addLog('info', "执行定时启动 [{$account['access_key_id']}]");

                        $mailRes = $this->notificationService->notifySchedule("定时启动", $account, "计划任务已触发，实例正在启动。");
                        $this->logNotificationResult($mailRes, $account['access_key_id']);

                        $forceRefresh = true;
                        $statusTransformed = true;
                    }
                }
                if ($account['stop_time'] && $currentUserTime === $account['stop_time']) {
                    if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                        $actions[] = "定时停止({$shutdownMode})";
                        $this->db->addLog('info', "执行定时停止 [{$account['access_key_id']}]");

                        $mailRes = $this->notificationService->notifySchedule("定时停止", $account, "计划任务已触发，实例已停止。");
                        $this->logNotificationResult($mailRes, $account['access_key_id']);

                        $forceRefresh = true;
                        $statusTransformed = true;
                    }
                }
            }

            // 2. 自适应心跳
            $lastUpdate = $account['updated_at'] ?? 0;
            $cachedStatus = $account['instance_status'] ?? 'Unknown';
            $isTransientState = in_array($cachedStatus, ['Starting', 'Stopping', 'Pending', 'Unknown']);
            $currentInterval = ($isTransientState || $statusTransformed) ? 60 : $userInterval;

            $shouldCheckApi = $forceRefresh || (($currentTime - $lastUpdate) > $currentInterval);

            if (date('i') === '00') {
                $shouldCheckApi = true;
            }

            $newUpdateTime = $currentTime;

            if (!$canTrafficMonitor && !$canInstanceControl) {
                $traffic = $account['traffic_used'];
                $status = $account['instance_status'] ?: 'Unknown';
                $apiStatusLog = "能力未启用";
            } elseif ($shouldCheckApi) {
                $newTraffic = $canTrafficMonitor
                    ? ($context !== null ? (float) ($context['trafficUsedGb'] ?? 0) : $this->safeGetTraffic($account))
                    : $account['traffic_used'];
                $status = $canInstanceControl
                    ? ($context !== null ? ($context['instanceStatus'] ?? 'Unknown') : $this->safeGetInstanceStatus($account))
                    : ($account['instance_status'] ?: 'Unknown');

                if ($canInstanceControl && $status === 'Unknown') {
                    usleep(500000);
                    $status = $this->safeGetInstanceStatus($account);
                }

                if ($canTrafficMonitor && $newTraffic < 0) {
                    $traffic = $account['traffic_used'];
                    $apiStatusLog = "流量API异常";
                    $newUpdateTime = $lastUpdate;
                } else {
                    $traffic = $newTraffic;
                    $apiStatusLog = $canTrafficMonitor ? "已更新" : "能力未启用";

                    if ($canTrafficMonitor) {
                        $this->db->addHourlyStat($account['id'], $traffic);
                        $this->db->addDailyStat($account['id'], $traffic);
                    }
                }

                if ($canInstanceControl && $status === 'Unknown') {
                    $newUpdateTime = $lastUpdate;
                    $apiStatusLog .= "(状态Unknown)";
                } elseif ($canInstanceControl) {
                    $apiStatusLog .= in_array($status, ['Starting', 'Stopping', 'Pending']) ? " [过渡态]" : " [稳定态]";
                }

                $this->configManager->updateAccountStatus($account['id'], $traffic, $status, $newUpdateTime);
            } else {
                $traffic = $account['traffic_used'];
                $status = $account['instance_status'];
                $timeLeft = $currentInterval - ($currentTime - $lastUpdate);
                $apiStatusLog = "缓存({$timeLeft}s)";
            }

            $maxTraffic = $account['max_traffic'];
            $usagePercent = ($maxTraffic > 0) ? round(($traffic / $maxTraffic) * 100, 2) : 0;
            $trafficDesc = $canTrafficMonitor ? "流量:{$usagePercent}%" : "流量:N/A";
            $isOverThreshold = $canTrafficMonitor && $usagePercent >= $threshold;

            // 3. 流量熔断
            if ($isOverThreshold) {
                $trafficDesc .= "[警告]";
                if ($shouldCheckApi) {
                    if ($thresholdAction === 'stop_and_notify') {
                        if ($status !== 'Stopped') {
                            if ($this->safeControlInstance($account, 'stop', $shutdownMode)) {
                                $actions[] = "超限关机";
                                $this->db->addLog('warning', "流量超限自动关机 [{$account['access_key_id']}] 使用率:{$usagePercent}%");
                                $this->configManager->updateAccountStatus($account['id'], $traffic, 'Stopping', $currentTime);
                                $status = 'Stopping';
                            }
                        }
                    } else {
                        $actions[] = "超限告警";
                        $this->db->addLog('warning', "流量超限触发告警 [{$account['access_key_id']}] 使用率:{$usagePercent}%");
                    }

                    $mailRes = $this->notificationService->sendTrafficWarning($account['access_key_id'], $traffic, $usagePercent, implode(',', $actions), $threshold);
                    $this->logNotificationResult($mailRes, $account['access_key_id']);
                }
            }

            // 4. 保活逻辑 (跳过已被定时任务操作的实例)
            if ($canScheduleManage && $keepAlive && !$isOverThreshold && !$statusTransformed) {
                if ($account['schedule_enabled'] == 0 || $this->isTimeInRange($currentUserTime, $account['start_time'], $account['stop_time'])) {
                    if ($status === 'Stopped') {
                        if ($this->safeControlInstance($account, 'start')) {
                            $actions[] = "保活启动";
                            $this->db->addLog('info', "执行保活启动 [{$account['access_key_id']}]");

                            $mailRes = $this->notificationService->notifySchedule("保活启动", $account, "检测到实例在工作时段非预期关机，已尝试自动启动。");
                            $this->logNotificationResult($mailRes, $account['access_key_id']);

                            $this->configManager->updateAccountStatus($account['id'], $traffic, 'Starting', $currentTime);
                            $status = 'Starting';
                        } else {
                            $apiStatusLog .= " [保活启动失败,下次重试]";
                        }
                    }
                }
            }

            if ($statusTransformed) {
                $tempStatus = in_array("定时启动", $actions) ? 'Starting' : 'Stopping';
                $this->configManager->updateAccountStatus($account['id'], $traffic, $tempStatus, $currentTime);
                $apiStatusLog .= " -> 强制过渡态";
            }

            $actionLog = empty($actions) ? "无动作" : implode(", ", $actions);
            $logLine = sprintf("%s %s | %s | %s | %s", $logPrefix, $actionLog, $trafficDesc, $status, $apiStatusLog);

            // --- 修改：将心跳日志写入数据库 ---
            $this->db->addLog('heartbeat', $logLine);
            $logs[] = $logLine;
        }

        $this->configManager->updateLastRunTime(time());

        return implode(PHP_EOL, $logs);
    }

    public function getStatusForFrontend()
    {
        if ($this->initError)
            return ['error' => $this->initError];

        $data = [];
        $threshold = (int) $this->configManager->get('traffic_threshold', 95);
        $userInterval = (int) $this->configManager->get('api_interval', 600);
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';

        $currentTime = time();
        $accounts = $this->configManager->getAccounts();
        $billingCycle = date('Y-m');

        foreach ($accounts as $account) {
            $provider = $this->getProviderForAccount($account);
            $capabilities = $provider->getCapabilities($account);

            if ($this->providerSupportsInstanceContext($provider, $account)) {
                $context = $this->safeDescribeAccountContext($provider, $account);
                $traffic = $context['trafficDataAvailable']
                    ? (float) ($context['trafficUsedGb'] ?? 0)
                    : (float) ($account['traffic_used'] ?? 0);
                $status = $context['instanceStatus'] ?? ($account['instance_status'] ?? 'Unknown');
                $this->configManager->updateAccountStatus($account['id'], $traffic, $status, $currentTime);
                if (!empty($context['trafficDataAvailable'])) {
                    $this->db->addHourlyStat($account['id'], $traffic);
                    $this->db->addDailyStat($account['id'], $traffic);
                }

                $maxTraffic = (float) ($account['max_traffic'] ?? 0);
                $usagePercent = ($maxTraffic > 0 && !empty($context['trafficDataAvailable']))
                    ? round(($traffic / $maxTraffic) * 100, 2)
                    : 0;

                $item = [
                    'id' => $account['id'],
                    'cloudProvider' => $account['cloud_provider'] ?? 'aliyun',
                    'capabilities' => $capabilities,
                    'account' => substr($account['access_key_id'], 0, 7) . '***',
                    'projectId' => $account['project_id'] ?? '',
                    'securityGroupId' => $account['security_group_id'] ?? '',
                    'flow_total' => $maxTraffic,
                    'flow_used' => round($traffic, 2),
                    'percentageOfUse' => $usagePercent,
                    'region' => $account['region_id'],
                    'regionName' => $this->getRegionName($account['region_id']),
                    'rate95' => ($maxTraffic > 0 && $usagePercent >= $threshold),
                    'threshold' => $threshold,
                    'instanceStatus' => $status,
                    'lastUpdated' => date('Y-m-d H:i:s', $currentTime),
                    'remark' => $account['remark'] ?? ''
                ];

                $data[] = array_merge($item, $this->buildHuaweiFrontendItem($account, $context));
                continue;
            }

            $lastUpdate = $account['updated_at'] ?? 0;
            $cachedStatus = $account['instance_status'] ?? 'Unknown';
            $newUpdateTime = $currentTime;

            $isTransientState = in_array($cachedStatus, ['Starting', 'Stopping', 'Pending', 'Unknown']);
            $checkInterval = $isTransientState ? 60 : $userInterval;

            if (empty($capabilities['traffic_monitor']) && empty($capabilities['instance_start_stop'])) {
                $traffic = $account['traffic_used'];
                $status = 'N/A';
            } elseif (($currentTime - $lastUpdate) > $checkInterval) {
                $newTraffic = $this->safeGetTraffic($account);
                $status = $this->safeGetInstanceStatus($account);

                if ($status === 'Unknown') {
                    usleep(500000);
                    $status = $this->safeGetInstanceStatus($account);
                }

                if ($newTraffic < 0) {
                    $traffic = $account['traffic_used'];
                    $newUpdateTime = $lastUpdate;
                } else {
                    $traffic = $newTraffic;
                    $this->db->addHourlyStat($account['id'], $traffic);
                    $this->db->addDailyStat($account['id'], $traffic);
                }

                if ($status === 'Unknown') {
                    $newUpdateTime = $lastUpdate;
                }

                $this->configManager->updateAccountStatus($account['id'], $traffic, $status, $newUpdateTime);
            } else {
                $traffic = $account['traffic_used'];
                $status = $account['instance_status'];
            }

            $usagePercent = ($account['max_traffic'] > 0) ? round(($traffic / $account['max_traffic']) * 100, 2) : 0;
            $isFull = $usagePercent >= $threshold;

            $item = [
                'id' => $account['id'],
                'cloudProvider' => $account['cloud_provider'] ?? 'aliyun',
                'capabilities' => $provider->getCapabilities($account),
                'account' => substr($account['access_key_id'], 0, 7) . '***',
                'projectId' => $account['project_id'] ?? '',
                'securityGroupId' => $account['security_group_id'] ?? '',
                'flow_total' => (float) $account['max_traffic'],
                'flow_used' => round($traffic, 2),
                'percentageOfUse' => $usagePercent,
                'region' => $account['region_id'],
                'regionName' => $this->getRegionName($account['region_id']),
                'rate95' => $isFull,
                'threshold' => $threshold,
                'instanceStatus' => $status,
                'lastUpdated' => date('Y-m-d H:i:s', $lastUpdate > 0 ? $lastUpdate : $currentTime),
                'remark' => $account['remark'] ?? ''
            ];

            if (!empty($capabilities['security_group_manage']) && empty($capabilities['traffic_monitor'])) {
                $securityGroupSummary = $this->safeGetSecurityGroupSummary($account);
                if ($securityGroupSummary) {
                    $item['securityGroupName'] = $securityGroupSummary['name'];
                    $item['securityGroupDescription'] = $securityGroupSummary['description'];
                }
            }

            // 注入费用数据 (如果启用)
            if ($billingEnabled && !empty($capabilities['billing_summary'])) {
                $item['cost'] = $this->safeGetBillingInfo($account, $billingCycle);
            }

            $data[] = $item;
        }

        return [
            'data' => $data,
            'system_last_run' => $this->configManager->getLastRunTime()
        ];
    }

    public function refreshAccount($id)
    {
        if ($this->initError)
            return false;

        $targetAccount = $this->configManager->getAccountById($id);
        if (!$targetAccount)
            return false;

        $currentTime = time();
        $provider = $this->getProviderForAccount($targetAccount);
        $capabilities = $provider->getCapabilities($targetAccount);

        if ($this->providerSupportsInstanceContext($provider, $targetAccount)) {
            $context = $this->safeDescribeAccountContext($provider, $targetAccount);
            $traffic = $context['trafficDataAvailable']
                ? (float) ($context['trafficUsedGb'] ?? 0)
                : (float) ($targetAccount['traffic_used'] ?? 0);
            $status = $context['instanceStatus'] ?? ($targetAccount['instance_status'] ?? 'Unknown');
            $this->configManager->updateAccountStatus($id, $traffic, $status, $currentTime);
            if (!empty($context['trafficDataAvailable'])) {
                $this->db->addHourlyStat($targetAccount['id'], $traffic);
                $this->db->addDailyStat($targetAccount['id'], $traffic);
            }
            return true;
        }

        $traffic = $this->safeGetTraffic($targetAccount);
        $status = $this->safeGetInstanceStatus($targetAccount);

        if (empty($capabilities['traffic_monitor']) && empty($capabilities['instance_start_stop'])) {
            $traffic = $targetAccount['traffic_used'];
            $status = $targetAccount['instance_status'] ?: 'Unknown';
        } elseif ($traffic < 0) {
            $traffic = $targetAccount['traffic_used'];
        } else {
            $this->db->addHourlyStat($targetAccount['id'], $traffic);
            $this->db->addDailyStat($targetAccount['id'], $traffic);
        }

        $this->configManager->updateAccountStatus($id, $traffic, $status, $currentTime);

        // 刷新账单数据：仅在启用费用监控 且 无有效缓存时调用 BSS API
        $billingError = null;
        $billingEnabled = $this->configManager->get('enable_billing', '0') === '1';
        if ($billingEnabled && !empty($capabilities['billing_summary'])) {
            $provider = $this->getProviderForAccount($targetAccount);
            $billingCycle = date('Y-m');

            // 余额：无有效缓存时重新获取
            $balanceCache = $this->db->getBillingCache($targetAccount['id'], 'balance', '', 21600);
            if (!$balanceCache) {
                try {
                    $balance = $provider->getAccountBalance($targetAccount);
                    $this->db->setBillingCache($targetAccount['id'], 'balance', '', $balance);
                } catch (\Exception $e) {
                    $billingError = '余额查询失败: ' . $e->getMessage();
                }
            }

            // 实例账单：无有效缓存时重新获取
            if (!empty($targetAccount['instance_id'])) {
                $billCache = $this->db->getBillingCache($targetAccount['id'], 'instance_bill', $billingCycle, 21600);
                if (!$billCache) {
                    try {
                        $bill = $provider->getInstanceBill($targetAccount, $billingCycle);
                        $this->db->setBillingCache($targetAccount['id'], 'instance_bill', $billingCycle, $bill);
                    } catch (\Exception $e) {
                        $billingError = ($billingError ? $billingError . '; ' : '') . '账单查询失败: ' . $e->getMessage();
                    }
                }
            }
        }

        if ($billingError) {
            $this->db->addLog('warning', "账单刷新异常 [{$targetAccount['access_key_id']}]: {$billingError}");
            return ['success' => true, 'billing_error' => $billingError];
        }

        return true;
    }

    /**
     * 公共接口：控制实例开关机
     * @param int $id 账户ID
     * @param string $action 'Start' 或 'Stop'
     */
    public function controlInstance($id, $action)
    {
        // 1. 强制设置 JSON 响应头
        header('Content-Type: application/json');

        if ($this->initError) {
            echo json_encode(['success' => false, 'message' => "系统未初始化"]);
            exit;
        }

        // 2. 获取账户配置
        $account = $this->configManager->getAccountById($id);
        if (!$account) {
            echo json_encode(['success' => false, 'message' => "账户配置未找到"]);
            exit;
        }

        $capabilities = $this->getProviderForAccount($account)->getCapabilities($account);
        if (empty($capabilities['instance_start_stop'])) {
            echo json_encode(['success' => false, 'message' => "当前云厂商暂不支持实例开关机"]);
            exit;
        }

        // 3. 获取当前实例状态 (从数据库读取最新状态，确保实时性)
        $currentStatus = $account['instance_status'] ?? 'Unknown';

        // --- 拦截逻辑 1: 状态冲突拦截 (Pending/Starting/Stopping) ---
        // 防止在状态变更中重复发送指令
        $transientStates = ['Pending', 'Starting', 'Stopping'];
        if (in_array($currentStatus, $transientStates)) {
            echo json_encode([
                'success' => false,
                'message' => "实例状态更新中 ({$currentStatus})，请稍后刷新页面查看最新状态，不要重复操作。"
            ]);
            exit;
        }

        // --- 拦截逻辑 2: 保活模式拦截 ---
        // 如果开启了“实例保活”，且用户尝试关机，则拒绝操作
        $keepAlive = $this->configManager->get('keep_alive', '0') === '1';
        if ($keepAlive && strtolower($action) === 'stop') {
            $this->db->addLog('warning', "拒绝手动关机请求 [{$account['access_key_id']}]: 实例保活功能已开启");
            echo json_encode([
                'success' => false,
                'message' => "操作被拒绝：当前开启了“实例保活”模式，不允许手动关机。"
            ]);
            exit;
        }

        // 4. 获取关机模式配置 (仅 Stop 时有效)
        $shutdownMode = $this->configManager->get('shutdown_mode', 'KeepCharging');

        // 5. 调用内部安全方法执行操作
        $result = $this->safeControlInstance($account, strtolower($action), $shutdownMode);

        if ($result === true) {
            $this->db->addLog('info', "手动控制实例 [{$account['access_key_id']}] 执行: {$action}");
            echo json_encode(['success' => true, 'message' => '指令发送成功']);
        } else {
            echo json_encode(['success' => false, 'message' => "操作执行失败: " . $result]);
        }
        exit;
    }

    public function getSecurityGroupRules($id)
    {
        if ($this->initError) {
            return ['success' => false, 'message' => $this->initError];
        }

        $account = $this->configManager->getAccountById($id);
        if (!$account) {
            return ['success' => false, 'message' => '账户配置未找到'];
        }

        try {
            $provider = $this->getProviderForAccount($account);
            $context = null;
            if ($this->providerSupportsInstanceContext($provider, $account)) {
                $context = $this->safeDescribeAccountContext($provider, $account);
                $groups = $context['securityGroups'] ?? [];
                if (empty($groups)) {
                    return [
                        'success' => false,
                        'message' => $context['discoveryMessage'] ?: '当前实例未发现可管理安全组',
                        'data' => [
                            'instance_id' => $context['instanceId'] ?? ($account['instance_id'] ?? ''),
                            'instance_name' => $context['instanceName'] ?? '',
                            'public_ip' => $context['publicIp'] ?? '',
                            'region_id' => $account['region_id'],
                            'discovery_status' => $context['discoveryStatus'] ?? '',
                            'discovery_mode' => $context['discoveryMode'] ?? '',
                            'discovery_message' => $context['discoveryMessage'] ?? '',
                            'using_fallback_security_group' => !empty($context['usingFallbackSecurityGroup']),
                            'security_groups' => []
                        ]
                    ];
                }
            } else {
                $groups = $provider->getInstanceSecurityGroups($account);
            }

            return [
                'success' => true,
                'data' => [
                    'instance_id' => $context['instanceId'] ?? ($account['instance_id'] ?? ''),
                    'instance_name' => $context['instanceName'] ?? '',
                    'public_ip' => $context['publicIp'] ?? '',
                    'region_id' => $account['region_id'],
                    'discovery_status' => $context['discoveryStatus'] ?? '',
                    'discovery_mode' => $context['discoveryMode'] ?? '',
                    'discovery_message' => $context['discoveryMessage'] ?? '',
                    'using_fallback_security_group' => !empty($context['usingFallbackSecurityGroup']),
                    'security_groups' => $groups
                ]
            ];
        } catch (ClientException $e) {
            $this->db->addLog('error', "安全组查询失败 [{$account['access_key_id']}]: 权限不足或配置错误");
            return ['success' => false, 'message' => '安全组查询失败：权限不足或配置错误'];
        } catch (HuaweiClientRequestException $e) {
            $message = $this->formatHuaweiExceptionMessage($e);
            $this->db->addLog('error', "安全组查询失败 [{$account['access_key_id']}]: {$message}");
            return ['success' => false, 'message' => '安全组查询失败：' . $message];
        } catch (ServerException $e) {
            $this->db->addLog('error', "安全组查询失败 [{$account['access_key_id']}]: 阿里云服务无响应");
            return ['success' => false, 'message' => '安全组查询失败：阿里云服务无响应'];
        } catch (\Exception $e) {
            $this->db->addLog('error', "安全组查询失败 [{$account['access_key_id']}]: " . $e->getMessage());
            return ['success' => false, 'message' => '安全组查询失败：' . $e->getMessage()];
        }
    }

    public function addSecurityGroupRule($id, $data)
    {
        if ($this->initError) {
            return ['success' => false, 'message' => $this->initError];
        }

        $account = $this->configManager->getAccountById($id);
        if (!$account) {
            return ['success' => false, 'message' => '账户配置未找到'];
        }

        try {
            $rule = $this->normalizeSecurityGroupRuleInput($data);
            $this->getProviderForAccount($account)->addSecurityGroupRule($account, $rule['security_group_id'], $rule);

            $this->db->addLog(
                'info',
                "新增安全组规则 [{$account['access_key_id']}] {$rule['security_group_id']} {$rule['ip_protocol']} {$rule['port_range']} <- {$rule['source_cidr_ip']}"
            );

            return ['success' => true, 'message' => '端口规则已添加'];
        } catch (ClientException $e) {
            $this->db->addLog('error', "新增安全组规则失败 [{$account['access_key_id']}]: 权限不足或配置错误");
            return ['success' => false, 'message' => '新增安全组规则失败：权限不足或配置错误'];
        } catch (HuaweiClientRequestException $e) {
            $message = $this->formatHuaweiExceptionMessage($e);
            $this->db->addLog('error', "新增安全组规则失败 [{$account['access_key_id']}]: {$message}");
            return ['success' => false, 'message' => '新增安全组规则失败：' . $message];
        } catch (ServerException $e) {
            $this->db->addLog('error', "新增安全组规则失败 [{$account['access_key_id']}]: 阿里云服务无响应");
            return ['success' => false, 'message' => '新增安全组规则失败：阿里云服务无响应'];
        } catch (\Exception $e) {
            $this->db->addLog('error', "新增安全组规则失败 [{$account['access_key_id']}]: " . $e->getMessage());
            return ['success' => false, 'message' => '新增安全组规则失败：' . $e->getMessage()];
        }
    }

    public function deleteSecurityGroupRule($id, $data)
    {
        if ($this->initError) {
            return ['success' => false, 'message' => $this->initError];
        }

        $account = $this->configManager->getAccountById($id);
        if (!$account) {
            return ['success' => false, 'message' => '账户配置未找到'];
        }

        try {
            $rule = $this->normalizeSecurityGroupRuleDeleteInput($data);
            $this->getProviderForAccount($account)->deleteSecurityGroupRule($account, $rule['security_group_id'], $rule);

            $ruleLabel = $rule['security_group_rule_id'] ?: ($rule['ip_protocol'] . ' ' . $rule['port_range']);
            $this->db->addLog('info', "删除安全组规则 [{$account['access_key_id']}] {$rule['security_group_id']} {$ruleLabel}");

            return ['success' => true, 'message' => '端口规则已删除'];
        } catch (ClientException $e) {
            $this->db->addLog('error', "删除安全组规则失败 [{$account['access_key_id']}]: 权限不足或配置错误");
            return ['success' => false, 'message' => '删除安全组规则失败：权限不足或配置错误'];
        } catch (HuaweiClientRequestException $e) {
            $message = $this->formatHuaweiExceptionMessage($e);
            $this->db->addLog('error', "删除安全组规则失败 [{$account['access_key_id']}]: {$message}");
            return ['success' => false, 'message' => '删除安全组规则失败：' . $message];
        } catch (ServerException $e) {
            $this->db->addLog('error', "删除安全组规则失败 [{$account['access_key_id']}]: 阿里云服务无响应");
            return ['success' => false, 'message' => '删除安全组规则失败：阿里云服务无响应'];
        } catch (\Exception $e) {
            $this->db->addLog('error', "删除安全组规则失败 [{$account['access_key_id']}]: " . $e->getMessage());
            return ['success' => false, 'message' => '删除安全组规则失败：' . $e->getMessage()];
        }
    }

    public function sendTestEmail($to)
    {
        return $this->notificationService->sendTestEmail($to);
    }

    public function sendTestTelegram($data)
    {
        return $this->notificationService->sendTestTelegram($data);
    }

    public function sendTestWebhook($data)
    {
        return $this->notificationService->sendTestWebhook($data);
    }

    private function logNotificationResult($result, $key)
    {
        if ($result === true) {
            $this->db->addLog('info', "通知推送成功 [$key]");
        } elseif ($result !== false && $result !== true) {
            $this->db->addLog('warning', "通知推送异常/失败 [$key]: " . strip_tags($result));
        }
    }

    private function getProviderForAccount($account)
    {
        return $this->providerFactory->getProvider($account['cloud_provider'] ?? 'aliyun');
    }

    private function providerSupportsInstanceContext($provider, $account)
    {
        return ($account['cloud_provider'] ?? 'aliyun') === 'huaweicloud'
            && is_object($provider)
            && method_exists($provider, 'describeAccountContext');
    }

    private function safeDescribeAccountContext($provider, $account)
    {
        try {
            return $provider->describeAccountContext($account);
        } catch (\Throwable $e) {
            return [
                'instanceId' => $account['instance_id'] ?? '',
                'instanceName' => '',
                'instanceStatus' => 'Unknown',
                'publicIp' => '',
                'securityGroups' => [],
                'securityGroupCount' => 0,
                'securityGroupNames' => [],
                'discoveryStatus' => 'error',
                'discoveryMode' => 'security_group_fallback',
                'discoveryMessage' => trim((string) $e->getMessage()) ?: '华为云实例发现失败',
                'usingFallbackSecurityGroup' => false,
                'fallbackSecurityGroupId' => trim((string) ($account['security_group_id'] ?? '')),
            ];
        }
    }

    private function buildHuaweiFrontendItem(array $account, array $context): array
    {
        $primaryGroup = $context['securityGroups'][0] ?? [];
        $trafficDataAvailable = !empty($context['trafficDataAvailable']);
        $trafficError = trim((string) ($context['trafficError'] ?? ''));
        $securityGroupCount = (int) ($context['securityGroupCount'] ?? 0);

        return [
            'instanceId' => $context['instanceId'] ?? ($account['instance_id'] ?? ''),
            'instanceName' => $context['instanceName'] ?? '',
            'publicIp' => $context['publicIp'] ?? '',
            'securityGroupCount' => $securityGroupCount,
            'securityGroupNames' => $context['securityGroupNames'] ?? [],
            'securityGroupName' => $primaryGroup['security_group_name'] ?? '',
            'securityGroupDescription' => $primaryGroup['description'] ?? '',
            'discoveryStatus' => $context['discoveryStatus'] ?? 'error',
            'discoveryMode' => $context['discoveryMode'] ?? 'security_group_fallback',
            'discoveryMessage' => $context['discoveryMessage'] ?? '',
            'usingFallbackSecurityGroup' => !empty($context['usingFallbackSecurityGroup']),
            'fallbackSecurityGroupId' => $context['fallbackSecurityGroupId'] ?? '',
            'trafficDataAvailable' => $trafficDataAvailable,
            'trafficError' => $trafficError,
            'primaryMetricUnit' => $trafficDataAvailable ? 'GB' : '',
            'primaryMetricLabel' => $trafficDataAvailable ? '公网流量' : '公网流量不可用',
            'primaryMetricValueText' => $trafficDataAvailable
                ? (string) round((float) ($context['trafficUsedGb'] ?? 0), 2)
                : '--',
            'secondaryMetricLabel' => '可管理安全组',
            'secondaryMetricValue' => $securityGroupCount,
        ];
    }

    private function getHuaweiVisualPercent(int $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        if ($count === 1) {
            return 35;
        }
        if ($count === 2) {
            return 60;
        }
        if ($count === 3) {
            return 80;
        }

        return 100;
    }

    private function formatHuaweiExceptionMessage($e)
    {
        if (!method_exists($e, 'getErrorCode') || !method_exists($e, 'getErrorMsg')) {
            return trim((string) $e->getMessage()) ?: '华为云请求失败';
        }

        $errorCode = (string) $e->getErrorCode();
        $errorMsg = trim((string) $e->getErrorMsg());

        if ($errorCode === 'VPC.9904') {
            return '当前 Region ID / Project ID 下找不到这个 Security Group ID';
        }

        if ($errorCode !== '' && $errorMsg !== '') {
            return "{$errorCode}: {$errorMsg}";
        }

        return $errorMsg ?: ($errorCode ?: '华为云请求失败');
    }

    private function safeGetSecurityGroupSummary($account)
    {
        try {
            $groups = $this->getProviderForAccount($account)->getInstanceSecurityGroups($account);
            if (empty($groups)) {
                return null;
            }

            return [
                'name' => $groups[0]['security_group_name'] ?? '',
                'description' => $groups[0]['description'] ?? ''
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function safeGetTraffic($account)
    {
        try {
            return $this->getProviderForAccount($account)->getTraffic($account);
        } catch (ClientException $e) {
            $code = $e->getErrorCode();
            $this->db->addLog('error', "流量查询配置错误: " . ($code ?: "鉴权失败"));
            return -1;
        } catch (ServerException $e) {
            $this->db->addLog('error', "流量查询失败: 阿里云接口超时");
            return -1;
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'cURL error') !== false) {
                $this->db->addLog('error', "流量查询失败: 网络连接超时");
            } elseif (strpos($e->getMessage(), 'SOCKS5') !== false || strpos($e->getMessage(), '代理') !== false) {
                $this->db->addLog('error', "流量查询失败: " . $e->getMessage());
            } else {
                $this->db->addLog('error', "流量查询失败: 系统未知错误");
            }
            return -1;
        }
    }

    private function safeGetInstanceStatus($account)
    {
        try {
            return $this->getProviderForAccount($account)->getInstanceStatus($account);
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'cURL error') !== false) {
            } elseif ($e instanceof ClientException) {
                $this->db->addLog('error', "实例状态查询配置错误: 鉴权失败");
            } elseif (strpos($e->getMessage(), 'SOCKS5') !== false || strpos($e->getMessage(), '代理') !== false) {
                $this->db->addLog('error', "实例状态查询失败: " . $e->getMessage());
            } else {
            }
            return 'Unknown';
        }
    }

    private function safeControlInstance($account, $action, $shutdownMode = 'KeepCharging')
    {
        try {
            return $this->getProviderForAccount($account)->controlInstance($account, $action, $shutdownMode);
        } catch (ClientException $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: 权限不足或配置错误");
            return false;
        } catch (ServerException $e) {
            $this->db->addLog('error', "实例操作失败 [{$action}]: 阿里云服务无响应");
            return false;
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'SOCKS5') !== false || strpos($e->getMessage(), '代理') !== false) {
                $this->db->addLog('error', "实例操作失败 [{$action}]: " . $e->getMessage());
            } else {
                $this->db->addLog('error', "实例操作失败 [{$action}]: 无法连接API");
            }
            return false;
        }
    }

    private function normalizeSecurityGroupRuleInput($data)
    {
        $rule = [
            'security_group_id' => trim((string) ($data['security_group_id'] ?? '')),
            'ip_protocol' => strtoupper(trim((string) ($data['ip_protocol'] ?? 'TCP'))),
            'port_range' => trim((string) ($data['port_range'] ?? '')),
            'source_cidr_ip' => trim((string) ($data['source_cidr_ip'] ?? '0.0.0.0/0')),
            'description' => trim((string) ($data['description'] ?? ''))
        ];

        if ($rule['security_group_id'] === '') {
            throw new \Exception('请选择安全组');
        }

        $allowedProtocols = ['TCP', 'UDP', 'ICMP', 'GRE', 'ALL'];
        if (!in_array($rule['ip_protocol'], $allowedProtocols, true)) {
            throw new \Exception('仅支持 TCP、UDP、ICMP、GRE、ALL 协议');
        }

        if (!$this->isValidIpv4OrCidr($rule['source_cidr_ip'])) {
            throw new \Exception('来源地址格式无效，请填写 IPv4 或 CIDR');
        }

        if (in_array($rule['ip_protocol'], ['TCP', 'UDP'], true)) {
            if (!preg_match('/^\d{1,5}\/\d{1,5}$/', $rule['port_range'])) {
                throw new \Exception('端口范围格式无效，请使用 80/80 或 3000/3999');
            }

            [$startPort, $endPort] = array_map('intval', explode('/', $rule['port_range']));
            if ($startPort < 0 || $endPort < 0 || $startPort > 65535 || $endPort > 65535 || $startPort > $endPort) {
                throw new \Exception('端口范围无效，请检查起止端口');
            }
        } elseif ($rule['port_range'] !== '-1/-1') {
            throw new \Exception('ICMP、GRE、ALL 协议的端口范围必须为 -1/-1');
        }

        if (strlen($rule['description']) > 128) {
            throw new \Exception('规则备注长度不能超过 128 个字符');
        }

        return $rule;
    }

    private function normalizeSecurityGroupRuleDeleteInput($data)
    {
        $rule = [
            'security_group_id' => trim((string) ($data['security_group_id'] ?? '')),
            'security_group_rule_id' => trim((string) ($data['security_group_rule_id'] ?? '')),
            'ip_protocol' => strtoupper(trim((string) ($data['ip_protocol'] ?? ''))),
            'port_range' => trim((string) ($data['port_range'] ?? '')),
            'source_cidr_ip' => trim((string) ($data['source_cidr_ip'] ?? '')),
            'source_group_id' => trim((string) ($data['source_group_id'] ?? '')),
            'source_prefix_list_id' => trim((string) ($data['source_prefix_list_id'] ?? '')),
            'policy' => trim((string) ($data['policy'] ?? 'accept')),
            'nic_type' => trim((string) ($data['nic_type'] ?? 'intranet'))
        ];

        if ($rule['security_group_id'] === '') {
            throw new \Exception('缺少安全组 ID');
        }

        if ($rule['security_group_rule_id'] === '' && $rule['source_cidr_ip'] === '' && $rule['source_group_id'] === '' && $rule['source_prefix_list_id'] === '') {
            throw new \Exception('缺少可删除的规则标识');
        }

        return $rule;
    }

    private function isValidIpv4OrCidr($value)
    {
        if ($value === '') {
            return false;
        }

        if (strpos($value, '/') === false) {
            return filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        }

        [$ip, $mask] = explode('/', $value, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }

        if (!ctype_digit((string) $mask)) {
            return false;
        }

        $mask = (int) $mask;
        return $mask >= 0 && $mask <= 32;
    }

    private function isTimeInRange($current, $start, $end)
    {
        if (!$start || !$end)
            return false;
        if ($start < $end) {
            return $current >= $start && $current < $end;
        } else {
            return $current >= $start || $current < $end;
        }
    }

    private function getRegionName($regionId)
    {
        $regions = [
            'cn-hongkong' => '中国香港',
            'ap-southeast-1' => '新加坡',
            'us-west-1' => '美国(硅谷)',
            'us-east-1' => '美国(弗吉尼亚)',
            'cn-hangzhou' => '华东1(杭州)',
            'cn-shanghai' => '华东2(上海)',
            'cn-qingdao' => '华北1(青岛)',
            'cn-beijing' => '华北2(北京)',
            'cn-zhangjiakou' => '华北3(张家口)',
            'cn-huhehaote' => '华北5(呼和浩特)',
            'cn-wulanchabu' => '华北6(乌兰察布)',
            'cn-shenzhen' => '华南1(深圳)',
            'cn-heyuan' => '华南2(河源)',
            'cn-guangzhou' => '华南3(广州)',
            'cn-chengdu' => '西南1(成都)',
            'cn-south-1' => '华南-广州',
            'cn-north-4' => '华北-北京4',
            'cn-east-3' => '华东-上海一',
            'cn-southwest-2' => '西南-贵阳一',
            'ap-northeast-1' => '日本(东京)',
            'ap-southeast-3' => '亚太-新加坡',
        ];
        return $regions[$regionId] ?? $regionId;
    }

    // ==================== 费用分析 ====================

    /**
     * 安全获取账户费用摘要信息 (带缓存)
     * 用于实例卡片上显示
     */
    private function safeGetBillingInfo($account, $billingCycle)
    {
        $costInfo = [
            'enabled' => true,
            'monthly_cost' => null,
            'balance' => null,
            'currency' => 'CNY',
            'last_updated' => null,
            'error' => null
        ];

        // 1. 尝试读取余额缓存
        $balanceCache = $this->db->getBillingCache($account['id'], 'balance', '', 21600);
        if ($balanceCache) {
            $costInfo['balance'] = $balanceCache['AvailableAmount'];
            $costInfo['currency'] = $balanceCache['Currency'] ?? 'CNY';
        } else {
            try {
                $balance = $this->getProviderForAccount($account)->getAccountBalance($account);
                $costInfo['balance'] = $balance['AvailableAmount'];
                $costInfo['currency'] = $balance['Currency'] ?? 'CNY';
                $this->db->setBillingCache($account['id'], 'balance', '', $balance);
            } catch (\Exception $e) {
                $costInfo['error'] = (strpos($e->getMessage(), 'SOCKS5') !== false || strpos($e->getMessage(), '代理') !== false)
                    ? '代理配置错误'
                    : '余额查询失败';
            }
        }

        // 2. 尝试读取实例账单缓存
        if (!empty($account['instance_id'])) {
            $billCache = $this->db->getBillingCache($account['id'], 'instance_bill', $billingCycle, 21600);
            if ($billCache) {
                $costInfo['monthly_cost'] = $billCache['TotalCost'];
            } else {
                try {
                    $bill = $this->getProviderForAccount($account)->getInstanceBill($account, $billingCycle);
                    $costInfo['monthly_cost'] = $bill['TotalCost'];
                    $this->db->setBillingCache($account['id'], 'instance_bill', $billingCycle, $bill);
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'SOCKS5') !== false || strpos($e->getMessage(), '代理') !== false) {
                        $costInfo['error'] = '代理配置错误';
                    } elseif ($costInfo['error']) {
                        $costInfo['error'] = 'BSS权限不足';
                    } else {
                        $costInfo['error'] = '账单查询失败';
                    }
                }
            }
        }

        $costInfo['last_updated'] = date('Y-m-d H:i:s');
        return $costInfo;
    }

    public function renderTemplate()
    {
        if (!file_exists('template.html'))
            return "File not found";
        ob_start();
        include 'template.html';
        return ob_get_clean();
    }
}
