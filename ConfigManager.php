<?php

class ConfigManager
{
    private $db;
    private $configCache = [];
    private $accountsCache = [];
    private $lastError = '';

    public function __construct(Database $db)
    {
        $this->db = $db->getPdo();
        $this->load();
    }

    public function load()
    {
        $stmt = $this->db->query("SELECT key, value FROM settings");
        while ($row = $stmt->fetch()) {
            $this->configCache[$row['key']] = $row['value'];
        }

        $stmt = $this->db->query("SELECT * FROM accounts ORDER BY id ASC");
        $this->accountsCache = $stmt->fetchAll();
    }

    public function get($key, $default = null)
    {
        return $this->configCache[$key] ?? $default;
    }

    public function getAllSettings()
    {
        return $this->configCache;
    }

    public function getAccounts()
    {
        return $this->accountsCache;
    }

    public function getAccountById($id)
    {
        foreach ($this->accountsCache as $acc) {
            if ($acc['id'] == $id)
                return $acc;
        }
        return null;
    }

    public function isInitialized()
    {
        return !empty($this->configCache['admin_password']);
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    private function saveSetting($key, $value)
    {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)");
        $stmt->execute([$key, $value]);
        $this->configCache[$key] = $value;
    }

    // --- 新增：心跳时间管理 ---

    public function updateLastRunTime($time)
    {
        $this->saveSetting('last_monitor_run', $time);
    }

    public function getLastRunTime()
    {
        return (int) ($this->configCache['last_monitor_run'] ?? 0);
    }

    // ------------------------

    public function updateConfig($data)
    {
        $this->lastError = '';

        try {
            $this->db->beginTransaction();

            // 1. 保存全局设置
            $this->saveSetting('admin_password', $data['admin_password']);
            $this->saveSetting('traffic_threshold', $data['traffic_threshold']);
            $this->saveSetting('enable_schedule_email', $data['enable_schedule_email'] ? '1' : '0');
            $this->saveSetting('shutdown_mode', $data['shutdown_mode']);
            $this->saveSetting('threshold_action', $data['threshold_action']);
            $this->saveSetting('keep_alive', isset($data['keep_alive']) && $data['keep_alive'] ? '1' : '0');
            $this->saveSetting('api_interval', $data['api_interval'] ?? 600);
            $this->saveSetting('enable_billing', isset($data['enable_billing']) && $data['enable_billing'] ? '1' : '0');

            if (isset($data['Notification'])) {
                // Email
                $this->saveSetting('notify_email_enabled', isset($data['Notification']['email_enabled']) && $data['Notification']['email_enabled'] ? '1' : '0');
                $this->saveSetting('notify_email', $data['Notification']['email'] ?? '');
                $this->saveSetting('notify_host', $data['Notification']['host'] ?? '');
                $this->saveSetting('notify_port', $data['Notification']['port'] ?? 465);
                $this->saveSetting('notify_username', $data['Notification']['username'] ?? '');
                $this->saveSetting('notify_password', $data['Notification']['password'] ?? '');
                $this->saveSetting('notify_secure', $data['Notification']['secure'] ?? 'ssl');

                // Telegram
                if (isset($data['Notification']['telegram'])) {
                    $tg = $data['Notification']['telegram'];
                    $this->saveSetting('notify_tg_enabled', isset($tg['enabled']) && $tg['enabled'] ? '1' : '0');
                    $this->saveSetting('notify_tg_token', $tg['token'] ?? '');
                    $this->saveSetting('notify_tg_chat_id', $tg['chat_id'] ?? '');
                    $this->saveSetting('notify_tg_proxy_type', $tg['proxy_type'] ?? 'none');
                    $this->saveSetting('notify_tg_proxy_url', $tg['proxy_url'] ?? '');
                    $this->saveSetting('notify_tg_proxy_ip', $tg['proxy_ip'] ?? '');
                    $this->saveSetting('notify_tg_proxy_port', $tg['proxy_port'] ?? '');
                    $this->saveSetting('notify_tg_proxy_user', $tg['proxy_user'] ?? '');
                    $this->saveSetting('notify_tg_proxy_pass', $tg['proxy_pass'] ?? '');
                }

                // Webhook
                if (isset($data['Notification']['webhook'])) {
                    $wh = $data['Notification']['webhook'];
                    $this->saveSetting('notify_wh_enabled', isset($wh['enabled']) && $wh['enabled'] ? '1' : '0');
                    $this->saveSetting('notify_wh_url', $wh['url'] ?? '');
                    $this->saveSetting('notify_wh_method', $wh['method'] ?? 'GET');
                    $this->saveSetting('notify_wh_request_type', $wh['request_type'] ?? 'JSON');
                    $this->saveSetting('notify_wh_headers', $wh['headers'] ?? '');
                    $this->saveSetting('notify_wh_body', $wh['body'] ?? '');
                }
            }

            // 2. 账号增量同步
            $newAccounts = $data['Accounts'] ?? [];
            $stmt = $this->db->query("SELECT id, cloud_provider, access_key_id, region_id, instance_id, security_group_id FROM accounts");
            $existingMap = [];
            while ($row = $stmt->fetch()) {
                // Use composite key for deduplication: Provider + AK + Region + InstanceID
                $compositeKey = ($row['cloud_provider'] ?? 'aliyun') . '|' . $row['access_key_id'] . '|' . $row['region_id'] . '|' . ($row['instance_id'] ?? '') . '|' . ($row['security_group_id'] ?? '');
                $existingMap[$compositeKey] = $row['id'];
            }

            $keptIds = [];
            $insertStmt = $this->db->prepare("INSERT INTO accounts (cloud_provider, access_key_id, access_key_secret, region_id, instance_id, project_id, security_group_id, max_traffic, schedule_enabled, start_time, stop_time, remark, site_type, api_proxy_enabled, api_proxy_host, api_proxy_port, api_proxy_user, api_proxy_pass, extra_config, traffic_used, instance_status, updated_at, last_keep_alive_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'Unknown', 0, 0)");
            $updateStmt = $this->db->prepare("UPDATE accounts SET cloud_provider = ?, access_key_secret = ?, region_id = ?, instance_id = ?, project_id = ?, security_group_id = ?, max_traffic = ?, schedule_enabled = ?, start_time = ?, stop_time = ?, remark = ?, site_type = ?, api_proxy_enabled = ?, api_proxy_host = ?, api_proxy_port = ?, api_proxy_user = ?, api_proxy_pass = ?, extra_config = ? WHERE id = ?");

            foreach ($newAccounts as $acc) {
                $provider = $acc['cloudProvider'] ?? 'aliyun';
                $key = $acc['AccessKeyId'];
                $secret = $acc['AccessKeySecret'] ?? '';
                $region = $acc['regionId'];
                $instance = trim((string) ($acc['instanceId'] ?? ''));
                $projectId = trim((string) ($acc['projectId'] ?? ''));
                $securityGroupId = trim((string) ($acc['securityGroupId'] ?? ''));
                $extraConfig = $this->normalizeExtraConfig($acc['extraConfig'] ?? []);
                $decodedExtraConfig = json_decode($extraConfig, true) ?: [];

                $this->validateAccountPayload($provider, $key, $secret, $region, $projectId, $instance, $decodedExtraConfig);

                $compositeKey = $provider . '|' . $key . '|' . $region . '|' . $instance . '|' . $securityGroupId;

                $params = [
                    $secret,
                    $region,
                    $instance,
                    $projectId,
                    $securityGroupId,
                    $acc['maxTraffic'],
                    ($acc['schedule']['enabled'] ?? false) ? 1 : 0,
                    $acc['schedule']['startTime'] ?? '',
                    $acc['schedule']['stopTime'] ?? '',
                    $acc['remark'] ?? '',
                    $acc['siteType'] ?? 'china',
                    (isset($acc['apiProxy']['enabled']) && $acc['apiProxy']['enabled']) ? 1 : 0,
                    trim((string) ($acc['apiProxy']['host'] ?? '')),
                    trim((string) ($acc['apiProxy']['port'] ?? '')),
                    trim((string) ($acc['apiProxy']['username'] ?? '')),
                    (string) ($acc['apiProxy']['password'] ?? ''),
                    $extraConfig
                ];

                if (isset($existingMap[$compositeKey])) {
                    array_unshift($params, $provider);
                    $id = $existingMap[$compositeKey];
                    $params[] = $id;
                    $updateStmt->execute($params);
                    $keptIds[] = $id;
                } else {
                    $insertParams = [$provider, $key];
                    array_push($insertParams, ...$params);
                    $insertStmt->execute($insertParams);
                    // For new inserts, we need to track the ID to avoid deleting it if user sends duplicate valid entries in one request? 
                    // But assume frontend sends unique list. If not, this logic might add duplicates. 
                    // Ideally we should track inserted IDs too but here we just rely on existingMap keys.
                    // Actually, if we just inserted, we can't easily get the ID back without lastInsertId but we don't strictly need it for the delete logic below if we assume input list is unique.
                    // However, to be safe against deleting effectively "new" accounts just added, let's just trust input list defines the "desired state".
                    // Wait, $idsToDelete is calculated from existingMap vs keptIds. If it's a new insert, it wasn't in existingMap, so it won't be in idsToDelete anyway.
                }
            }

            // 3. 删除移除的账号
            $idsToDelete = array_diff(array_values($existingMap), $keptIds);
            if (!empty($idsToDelete)) {
                $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
                $deleteStmt = $this->db->prepare("DELETE FROM accounts WHERE id IN ($placeholders)");
                $deleteStmt->execute(array_values($idsToDelete));
            }

            $this->db->commit();

            // 4. 重排 ID
            $this->reorderIds();

            // 5. 刷新缓存
            $this->load();
            return true;
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            if ($this->db->inTransaction())
                $this->db->rollBack();
            return false;
        }
    }

    private function normalizeExtraConfig($extraConfig): string
    {
        if (is_string($extraConfig)) {
            $decoded = json_decode($extraConfig, true);
            if (!is_array($decoded)) {
                throw new Exception('extraConfig 必须是有效 JSON');
            }
            $extraConfig = $decoded;
        }

        if (!is_array($extraConfig)) {
            throw new Exception('extraConfig 必须是对象');
        }

        return json_encode($extraConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function validateAccountPayload($provider, $key, $secret, $region, $projectId, $instance, array $extraConfig = [])
    {
        $key = trim((string) $key);
        $secret = trim((string) $secret);
        $region = trim((string) $region);
        $projectId = trim((string) $projectId);
        $instance = trim((string) $instance);

        if ($provider === 'gcp') {
            $zone = trim((string) ($extraConfig['zone'] ?? $region));
            $serviceAccountJson = trim((string) ($extraConfig['service_account_json'] ?? ''));
            if ($projectId === '' || $zone === '' || $instance === '' || $serviceAccountJson === '') {
                throw new Exception('GCP 账号必须填写 Project ID、Zone、Instance Name 和 Service Account JSON');
            }
            $decoded = json_decode($serviceAccountJson, true);
            if (!is_array($decoded)) {
                throw new Exception('GCP Service Account JSON 格式无效');
            }
            return;
        }

        if ($provider === 'huaweicloud') {
            if ($key === '' || $secret === '' || $region === '' || $projectId === '' || $instance === '') {
                throw new Exception('华为云账号必须填写 AccessKey、Region ID、Project ID 和 Instance ID');
            }
            return;
        }

        if (in_array($provider, ['aliyun', 'tencentcloud', 'aws'], true)) {
            if ($key === '' || $secret === '' || $region === '' || $instance === '') {
                throw new Exception('账号缺少 AccessKey、Region ID 或 Instance ID');
            }
            return;
        }

        throw new Exception("暂不支持的云厂商: {$provider}");
    }

    private function reorderIds()
    {
        try {
            $this->db->beginTransaction();
            $stmt = $this->db->query("SELECT * FROM accounts ORDER BY id ASC");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($rows)) {
                $this->db->exec("DELETE FROM accounts");
                $this->db->exec("DELETE FROM sqlite_sequence WHERE name='accounts'");

                $insertStmt = $this->db->prepare("INSERT INTO accounts (id, cloud_provider, access_key_id, access_key_secret, region_id, instance_id, project_id, security_group_id, max_traffic, schedule_enabled, start_time, stop_time, remark, site_type, api_proxy_enabled, api_proxy_host, api_proxy_port, api_proxy_user, api_proxy_pass, extra_config, traffic_used, instance_status, updated_at, last_keep_alive_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

                $newId = 1;
                foreach ($rows as $row) {
                    $insertStmt->execute([
                        $newId++,
                        $row['cloud_provider'] ?? 'aliyun',
                        $row['access_key_id'],
                        $row['access_key_secret'],
                        $row['region_id'],
                        $row['instance_id'],
                        $row['project_id'] ?? '',
                        $row['security_group_id'] ?? '',
                        $row['max_traffic'],
                        $row['schedule_enabled'],
                        $row['start_time'],
                        $row['stop_time'],
                        $row['remark'] ?? '',
                        $row['site_type'] ?? 'china',
                        $row['api_proxy_enabled'] ?? 0,
                        $row['api_proxy_host'] ?? '',
                        $row['api_proxy_port'] ?? '',
                        $row['api_proxy_user'] ?? '',
                        $row['api_proxy_pass'] ?? '',
                        $row['extra_config'] ?? '{}',
                        $row['traffic_used'],
                        $row['instance_status'],
                        $row['updated_at'],
                        $row['last_keep_alive_at']
                    ]);
                }
            }
            $this->db->commit();
        } catch (Exception $e) {
            if ($this->db->inTransaction())
                $this->db->rollBack();
        }
    }

    public function updateAccountStatus($id, $traffic, $status, $updatedAt)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET traffic_used = ?, instance_status = ?, updated_at = ? WHERE id = ?");
        return $stmt->execute([$traffic, $status, $updatedAt, $id]);
    }

    public function updateLastKeepAlive($id, $time)
    {
        $stmt = $this->db->prepare("UPDATE accounts SET last_keep_alive_at = ? WHERE id = ?");
        return $stmt->execute([$time, $id]);
    }
}
