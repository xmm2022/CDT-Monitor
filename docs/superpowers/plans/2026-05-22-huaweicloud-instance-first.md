# HuaweiCloud Instance-First Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 把华为云账号从“单个安全组 ID 驱动”升级为“实例优先 + 自动发现安全组 + 兼容回退”体验，并让首页卡片与端口管理入口尽量对齐阿里云。

**Architecture:** 保持现有 provider 抽象不破坏阿里云路径，在 `HuaweiCloudProvider` 内新增 ECS 实例发现能力，并由 `AliyunTrafficCheck` 统一组装华为云实例上下文、状态和前端字段。前端继续复用现有卡片与弹窗骨架，只调整华为云语义、字段和状态展示。

**Tech Stack:** PHP 8.x, SQLite, HuaweiCloud PHP SDK, Vue 3 (CDN build), Tailwind CSS

---

### Task 1: 建立失败测试与本轮测试支架

**Files:**
- Create: `tests/bootstrap.php`
- Create: `tests/ConfigManagerHuaweiValidationTest.php`
- Create: `tests/HuaweiCloudProviderDiscoveryTest.php`
- Modify: `providers/HuaweiCloudProvider.php`
- Modify: `ConfigManager.php`

- [ ] **Step 1: 写出华为云配置校验失败测试**

```php
$manager = createConfigManagerForTest();
$result = $manager->updateConfig([
    'admin_password' => 'pass',
    'traffic_threshold' => 95,
    'enable_schedule_email' => false,
    'shutdown_mode' => 'KeepCharging',
    'threshold_action' => 'stop_and_notify',
    'keep_alive' => false,
    'api_interval' => 600,
    'enable_billing' => false,
    'Notification' => [],
    'Accounts' => [[
        'cloudProvider' => 'huaweicloud',
        'AccessKeyId' => 'hw-ak',
        'AccessKeySecret' => 'hw-sk',
        'regionId' => 'ap-southeast-3',
        'projectId' => 'project-1',
        'instanceId' => '',
        'securityGroupId' => '',
        'maxTraffic' => 0,
        'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
        'remark' => '',
        'siteType' => 'china',
        'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => '']
    ]]
]);

assertSame(false, $result);
```

- [ ] **Step 2: 运行测试确认它先失败**

Run: `php /root/CDT-Monitor/tests/ConfigManagerHuaweiValidationTest.php`
Expected: FAIL，提示当前 `updateConfig()` 仍接受缺少 `Instance ID` 的华为云账号。

- [ ] **Step 3: 写出华为云实例发现结果组装失败测试**

```php
$provider = new TestHuaweiCloudProvider([
    'instance' => [
        'id' => 'server-1',
        'name' => 'ecs-demo',
        'status' => 'ACTIVE',
        'public_ip' => '1.2.3.4',
        'security_groups' => [
            ['id' => 'sg-1', 'name' => 'web'],
            ['id' => 'sg-2', 'name' => 'admin'],
        ],
    ],
]);

$summary = $provider->describeAccountContext($account);

assertSame('success', $summary['discoveryStatus']);
assertSame('instance', $summary['discoveryMode']);
assertSame(2, $summary['securityGroupCount']);
assertSame('ecs-demo', $summary['instanceName']);
assertSame('1.2.3.4', $summary['publicIp']);
```

- [ ] **Step 4: 运行测试确认它先失败**

Run: `php /root/CDT-Monitor/tests/HuaweiCloudProviderDiscoveryTest.php`
Expected: FAIL，提示缺少 `describeAccountContext()` 或返回结构不完整。

### Task 2: 实现华为云 provider 的实例发现与 fallback

**Files:**
- Modify: `providers/HuaweiCloudProvider.php`
- Modify: `providers/CloudProviderInterface.php` (only if strictly required)
- Test: `tests/HuaweiCloudProviderDiscoveryTest.php`

- [ ] **Step 1: 实现最小实例发现接口**

```php
public function describeAccountContext(array $account): array
{
    if (empty($account['project_id']) || empty($account['instance_id'])) {
        return $this->buildIncompleteContext($account);
    }

    try {
        $server = $this->fetchServerDetail($account);
        $groups = $this->loadSecurityGroupsFromServer($account, $server);
        return $this->buildInstanceContext($account, $server, $groups);
    } catch (\Throwable $e) {
        return $this->buildFallbackContext($account, $e);
    }
}
```

- [ ] **Step 2: 补 ECS client、实例状态映射和公网 IP 解析**

```php
private function mapHuaweiStatus(string $status): string
{
    return match (strtoupper($status)) {
        'ACTIVE' => 'Running',
        'SHUTOFF' => 'Stopped',
        'BUILD', 'REBOOT', 'HARD_REBOOT' => 'Starting',
        default => 'Unknown',
    };
}
```

- [ ] **Step 3: 让安全组查询默认走实例自动发现，失败再回退兼容 SG**

```php
public function getInstanceSecurityGroups(array $account)
{
    $context = $this->describeAccountContext($account);
    if (!empty($context['securityGroups'])) {
        return $context['securityGroups'];
    }
    throw new Exception($context['discoveryMessage'] ?: '未发现可管理安全组');
}
```

- [ ] **Step 4: 跑 discovery 测试直到通过**

Run: `php /root/CDT-Monitor/tests/HuaweiCloudProviderDiscoveryTest.php`
Expected: PASS

### Task 3: 实现配置校验、状态组装和前端展示

**Files:**
- Modify: `ConfigManager.php`
- Modify: `AliyunTrafficCheck.php`
- Modify: `template.html`
- Modify: `MULTI_CLOUD_PLAN.md`
- Test: `tests/ConfigManagerHuaweiValidationTest.php`

- [ ] **Step 1: 在 `ConfigManager` 中收紧华为云必填校验**

```php
if ($provider === 'huaweicloud') {
    if ($key === '' || $secret === '' || $region === '' || $projectId === '' || $instance === '') {
        throw new Exception('华为云账号缺少必填字段');
    }
}
```

- [ ] **Step 2: 在 `AliyunTrafficCheck` 中组装华为云实例上下文字段**

```php
if (($account['cloud_provider'] ?? 'aliyun') === 'huaweicloud') {
    $context = $provider->describeAccountContext($account);
    $item = array_merge($item, $this->buildHuaweiFrontendItem($account, $context));
}
```

- [ ] **Step 3: 前端改配置页、卡片和端口管理弹窗**

```js
if (item.cloudProvider === 'huaweicloud') {
  return item.discoveryStatus === 'success'
    ? `${item.flow_used} 组`
    : (item.discoveryMessage || '待补全');
}
```

- [ ] **Step 4: 跑配置校验测试直到通过**

Run: `php /root/CDT-Monitor/tests/ConfigManagerHuaweiValidationTest.php`
Expected: PASS

### Task 4: 整体验证

**Files:**
- Verify only

- [ ] **Step 1: 运行 PHP 语法检查**

Run: `php -l /root/CDT-Monitor/providers/HuaweiCloudProvider.php && php -l /root/CDT-Monitor/ConfigManager.php && php -l /root/CDT-Monitor/AliyunTrafficCheck.php && php -l /root/CDT-Monitor/index.php`
Expected: 所有文件 `No syntax errors detected`

- [ ] **Step 2: 运行本轮新增测试**

Run: `php /root/CDT-Monitor/tests/ConfigManagerHuaweiValidationTest.php && php /root/CDT-Monitor/tests/HuaweiCloudProviderDiscoveryTest.php`
Expected: PASS

- [ ] **Step 3: 手动回归关键接口**

Run: `php -r 'require "/root/CDT-Monitor/AliyunTrafficCheck.php"; $app = new AliyunTrafficCheck(); echo json_encode($app->getConfigForFrontend());'`
Expected: 返回 JSON，不抛出初始化级 fatal error
