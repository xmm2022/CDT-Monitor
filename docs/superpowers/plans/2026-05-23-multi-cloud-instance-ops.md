# Multi-Cloud Instance Operations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Tencent Cloud, AWS, and GCP providers for manual instance operations and security rule management without changing Aliyun and Huawei Cloud behavior.

**Architecture:** Add a provider-level instance-context contract, persist provider-specific configuration in `accounts.extra_config`, and implement Tencent Cloud first as the complete reference provider. AWS and GCP reuse the same normalized context and security-rule shapes, with GCP presenting firewall rules through the existing security-rule modal.

**Tech Stack:** PHP 8.2, SQLite, Composer, Tencent Cloud CVM SDK, AWS SDK for PHP, Google Cloud Compute PHP client, Vue 3, Tailwind CSS, Docker

---

## File Structure

- Create `providers/CloudInstanceContextInterface.php`: explicit optional provider contract for instance context discovery.
- Create `providers/InstanceContextHelpers.php`: shared helpers for context arrays, status mapping, port normalization, and `extra_config` parsing.
- Create `providers/TencentCloudProvider.php`: Tencent Cloud CVM implementation for instance status, start/stop, and security group ingress rules.
- Create `providers/AwsProvider.php`: AWS EC2 implementation for instance status, start/stop, and security group ingress rules.
- Create `providers/GcpProvider.php`: GCP Compute Engine implementation for instance status, start/stop, and firewall rule management.
- Modify `providers/HuaweiCloudProvider.php`: implement `CloudInstanceContextInterface` and reuse shared context status names where practical.
- Modify `providers/ProviderFactory.php`: register `tencentcloud`, `aws`, and `gcp`.
- Modify `Database.php`: add `accounts.extra_config`.
- Modify `ConfigManager.php`: round-trip `extra_config`, validate required fields per provider, and preserve existing account behavior.
- Modify `AliyunTrafficCheck.php`: promote instance-context rendering from Huawei-only to provider-agnostic.
- Modify `template.html`: add provider options, metadata, capabilities, config fields, and GCP firewall labels.
- Modify `composer.json`, `composer.lock`, and `Dockerfile`: add official SDK dependencies and preserve required SDK files in the runtime image.
- Create tests in `tests/` for schema/config, context contract, each new provider normalizer, factory registration, and frontend config payload.

Use Docker for PHP verification because this host does not currently provide `php` or `sqlite3` directly.

---

### Task 1: Add `extra_config` Persistence and Validation Tests

**Files:**
- Modify: `Database.php`
- Modify: `ConfigManager.php`
- Modify: `AliyunTrafficCheck.php`
- Create: `tests/ConfigManagerExtraConfigTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/ConfigManagerExtraConfigTest.php`:

```php
<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';

$dbPath = createTempDbPath('config-extra-config');
$database = new Database($dbPath);
$manager = new ConfigManager($database);

$payload = [
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
        'cloudProvider' => 'gcp',
        'AccessKeyId' => '',
        'AccessKeySecret' => '',
        'regionId' => 'asia-east1-a',
        'instanceId' => 'instance-1',
        'projectId' => 'project-1',
        'securityGroupId' => '',
        'maxTraffic' => 0,
        'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
        'remark' => 'gcp vm',
        'siteType' => 'china',
        'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => ''],
        'extraConfig' => [
            'zone' => 'asia-east1-a',
            'network' => 'default',
            'target_tags' => 'web,ssh',
            'firewall_rule_prefix' => 'cdt-monitor-',
            'service_account_json' => '{"type":"service_account","project_id":"project-1"}',
        ],
    ]],
];

$result = $manager->updateConfig($payload);
assertSame(true, $result, 'valid gcp extraConfig should be accepted');

$accounts = $manager->getAccounts();
assertSame(1, count($accounts), 'one account should be persisted');
assertSame('gcp', $accounts[0]['cloud_provider'], 'provider should persist');

$extra = json_decode($accounts[0]['extra_config'], true);
assertSame('asia-east1-a', $extra['zone'], 'zone should persist in extra_config');
assertSame('cdt-monitor-', $extra['firewall_rule_prefix'], 'prefix should persist in extra_config');
assertSame('{"type":"service_account","project_id":"project-1"}', $extra['service_account_json'], 'service account json should persist verbatim');

$invalid = $payload;
$invalid['Accounts'][0]['extraConfig']['service_account_json'] = '';
$result = $manager->updateConfig($invalid);
assertSame(false, $result, 'gcp account without service_account_json should be rejected');
assertContains('GCP', $manager->getLastError(), 'error should mention GCP');

pass('ConfigManager extra_config persistence and validation');
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/ConfigManagerExtraConfigTest.php'
```

Expected: FAIL because `accounts.extra_config` does not exist and GCP validation is not implemented.

- [ ] **Step 3: Add the database column**

In `Database.php`, add this line in `initSchema()` after the existing `api_proxy_pass` ensure:

```php
$this->ensureColumn('accounts', 'extra_config', "TEXT DEFAULT '{}'");
```

Also add `extra_config TEXT DEFAULT '{}'` to the `CREATE TABLE IF NOT EXISTS accounts` statement.

- [ ] **Step 4: Persist and reload `extra_config`**

In `ConfigManager.php`, update the insert, update, and reorder SQL column lists to include `extra_config`.

Inside the account loop in `updateConfig()`, add:

```php
$extraConfig = $this->normalizeExtraConfig($acc['extraConfig'] ?? []);
```

Add this private method:

```php
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
```

Pass `$extraConfig` into the account insert and update parameter arrays after `api_proxy_pass`.

- [ ] **Step 5: Validate provider-specific account fields**

Replace `validateAccountPayload()` with this signature and implementation:

```php
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
```

Call it as:

```php
$decodedExtraConfig = json_decode($extraConfig, true) ?: [];
$this->validateAccountPayload($provider, $key, $secret, $region, $projectId, $instance, $decodedExtraConfig);
```

- [ ] **Step 6: Include `extraConfig` in frontend config**

In `AliyunTrafficCheck::getConfigForFrontend()`, decode `extra_config`:

```php
$extraConfig = json_decode($row['extra_config'] ?? '{}', true);
if (!is_array($extraConfig)) {
    $extraConfig = [];
}
```

Add to `$accountConfig`:

```php
'extraConfig' => $extraConfig,
```

- [ ] **Step 7: Run tests**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/ConfigManagerHuaweiValidationTest.php && php tests/ConfigManagerExtraConfigTest.php'
```

Expected:

```text
PASS: ConfigManager Huawei validation
PASS: ConfigManager extra_config persistence and validation
```

- [ ] **Step 8: Commit**

```bash
git add Database.php ConfigManager.php AliyunTrafficCheck.php tests/ConfigManagerExtraConfigTest.php
git commit -m "feat: add provider extra config persistence"
```

---

### Task 2: Add the Instance Context Contract and Shared Helpers

**Files:**
- Create: `providers/CloudInstanceContextInterface.php`
- Create: `providers/InstanceContextHelpers.php`
- Modify: `providers/HuaweiCloudProvider.php`
- Modify: `AliyunTrafficCheck.php`
- Create: `tests/InstanceContextHelpersTest.php`

- [ ] **Step 1: Write the failing helper test**

Create `tests/InstanceContextHelpersTest.php`:

```php
<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/InstanceContextHelpers.php';

class TestInstanceContextHelper
{
    use InstanceContextHelpers;

    public function status(string $status): string
    {
        return $this->normalizeInstanceStatus($status, [
            'RUNNING' => 'Running',
            'STOPPED' => 'Stopped',
            'PENDING' => 'Pending',
        ]);
    }

    public function incomplete(array $account, string $message): array
    {
        return $this->buildIncompleteInstanceContext($account, $message);
    }

    public function extra(array $account): array
    {
        return $this->decodeExtraConfig($account);
    }
}

$helper = new TestInstanceContextHelper();

assertSame('Running', $helper->status('RUNNING'), 'RUNNING should normalize');
assertSame('Stopped', $helper->status('stopped'), 'case-insensitive status should normalize');
assertSame('Unknown', $helper->status('unexpected'), 'unknown status should normalize to Unknown');

$context = $helper->incomplete(['instance_id' => 'i-1', 'security_group_id' => 'sg-1'], 'missing key');
assertSame('incomplete', $context['discoveryStatus'], 'incomplete status');
assertSame('i-1', $context['instanceId'], 'instance id should be preserved');
assertSame('sg-1', $context['fallbackSecurityGroupId'], 'fallback sg should be preserved');
assertSame(false, $context['trafficDataAvailable'], 'traffic should be unavailable');

$extra = $helper->extra(['extra_config' => '{"zone":"asia-east1-a"}']);
assertSame('asia-east1-a', $extra['zone'], 'extra_config should decode');

pass('InstanceContextHelpers');
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/InstanceContextHelpersTest.php'
```

Expected: FAIL because `providers/InstanceContextHelpers.php` does not exist.

- [ ] **Step 3: Create the explicit contract**

Create `providers/CloudInstanceContextInterface.php`:

```php
<?php

interface CloudInstanceContextInterface
{
    public function describeAccountContext(array $account): array;
}
```

- [ ] **Step 4: Create shared helper trait**

Create `providers/InstanceContextHelpers.php`:

```php
<?php

trait InstanceContextHelpers
{
    protected function normalizeInstanceStatus(string $status, array $map): string
    {
        $key = strtoupper(trim($status));
        return $map[$key] ?? 'Unknown';
    }

    protected function decodeExtraConfig(array $account): array
    {
        $raw = $account['extra_config'] ?? '{}';
        if (is_array($raw)) {
            return $raw;
        }

        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function buildIncompleteInstanceContext(array $account, string $message): array
    {
        return $this->buildBaseInstanceContext($account, [
            'discoveryStatus' => 'incomplete',
            'discoveryMode' => 'incomplete',
            'discoveryMessage' => $message,
        ]);
    }

    protected function buildErrorInstanceContext(array $account, string $message): array
    {
        return $this->buildBaseInstanceContext($account, [
            'discoveryStatus' => 'error',
            'discoveryMode' => 'instance',
            'discoveryMessage' => $message,
        ]);
    }

    protected function buildSuccessInstanceContext(array $account, array $values): array
    {
        return $this->buildBaseInstanceContext($account, array_merge([
            'discoveryStatus' => 'success',
            'discoveryMode' => 'instance',
            'discoveryMessage' => '',
        ], $values));
    }

    protected function buildBaseInstanceContext(array $account, array $values): array
    {
        return array_merge([
            'instanceId' => $account['instance_id'] ?? '',
            'instanceName' => '',
            'instanceStatus' => 'Unknown',
            'publicIp' => '',
            'securityGroups' => [],
            'securityGroupCount' => 0,
            'securityGroupNames' => [],
            'discoveryStatus' => 'error',
            'discoveryMode' => 'instance',
            'discoveryMessage' => '',
            'usingFallbackSecurityGroup' => false,
            'fallbackSecurityGroupId' => trim((string) ($account['security_group_id'] ?? '')),
            'trafficDataAvailable' => false,
            'trafficUsedGb' => null,
            'trafficError' => '',
        ], $values);
    }

    protected function collectSecurityGroupNames(array $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            $name = trim((string) ($group['security_group_name'] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }
        return $names;
    }
}
```

- [ ] **Step 5: Make Huawei provider implement the contract**

In `providers/HuaweiCloudProvider.php`, add:

```php
require_once __DIR__ . '/CloudInstanceContextInterface.php';
require_once __DIR__ . '/InstanceContextHelpers.php';
```

Change the class declaration:

```php
class HuaweiCloudProvider implements CloudProviderInterface, CloudInstanceContextInterface
{
    use InstanceContextHelpers;
```

Keep existing Huawei behavior. Do not rename its private helper methods in this task.

- [ ] **Step 6: Make instance context provider-agnostic**

In `AliyunTrafficCheck::providerSupportsInstanceContext()`, replace the method with:

```php
private function providerSupportsInstanceContext($provider, $account)
{
    return is_object($provider)
        && method_exists($provider, 'describeAccountContext');
}
```

Rename `buildHuaweiFrontendItem()` to `buildInstanceContextFrontendItem()`, then keep `buildHuaweiFrontendItem()` as a compatibility wrapper:

```php
private function buildHuaweiFrontendItem(array $account, array $context): array
{
    return $this->buildInstanceContextFrontendItem($account, $context);
}
```

Update the call in `getStatusForFrontend()` to:

```php
$data[] = array_merge($item, $this->buildInstanceContextFrontendItem($account, $context));
```

- [ ] **Step 7: Run helper and existing discovery tests**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/InstanceContextHelpersTest.php && php tests/HuaweiCloudProviderDiscoveryTest.php'
```

Expected:

```text
PASS: InstanceContextHelpers
PASS: HuaweiCloudProvider discovery summary
```

- [ ] **Step 8: Commit**

```bash
git add providers/CloudInstanceContextInterface.php providers/InstanceContextHelpers.php providers/HuaweiCloudProvider.php AliyunTrafficCheck.php tests/InstanceContextHelpersTest.php
git commit -m "feat: add shared instance context contract"
```

---

### Task 3: Add Official SDK Dependencies and Build Verification

**Files:**
- Modify: `composer.json`
- Modify: `composer.lock`
- Modify: `Dockerfile`

- [ ] **Step 1: Add dependencies through Composer**

Run:

```bash
docker run --rm \
  -v /home/nax/CDT-Monitor:/app \
  -w /app composer:2 \
  composer require tencentcloud/cvm aws/aws-sdk-php google/cloud-compute --ignore-platform-reqs --no-scripts
```

Expected: `composer.json` and `composer.lock` are updated with the three SDK dependencies.

- [ ] **Step 2: Keep required SDK modules during Docker build**

In `Dockerfile`, keep the existing Huawei Cloud SDK pruning. Do not prune Tencent Cloud CVM, AWS EC2, or Google Compute files in this task.

If a new pruning command is added later, it must preserve these paths:

```text
vendor/tencentcloud/cvm
vendor/tencentcloud/common
vendor/aws/aws-sdk-php/src/Ec2
vendor/google/cloud-compute
```

- [ ] **Step 3: Verify autoload resolves SDK classes**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php -r "require \"vendor/autoload.php\"; echo class_exists(\"TencentCloud\\\\Cvm\\\\V20170312\\\\CvmClient\") ? \"tencent-ok\\n\" : \"tencent-missing\\n\"; echo class_exists(\"Aws\\\\Ec2\\\\Ec2Client\") ? \"aws-ok\\n\" : \"aws-missing\\n\"; echo class_exists(\"Google\\\\Cloud\\\\Compute\\\\V1\\\\InstancesClient\") ? \"gcp-ok\\n\" : \"gcp-missing\\n\";"'
```

Expected:

```text
tencent-ok
aws-ok
gcp-ok
```

- [ ] **Step 4: Commit**

```bash
git add composer.json composer.lock Dockerfile
git commit -m "build: add multi-cloud provider sdks"
```

---

### Task 4: Implement Tencent Cloud Provider Normalizers with Tests

**Files:**
- Create: `providers/TencentCloudProvider.php`
- Modify: `providers/ProviderFactory.php`
- Create: `tests/TencentCloudProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/TencentCloudProviderTest.php`:

```php
<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/TencentCloudProvider.php';

$provider = new TencentCloudProvider();

$caps = $provider->getCapabilities([]);
assertSame(true, $caps['instance_start_stop'], 'Tencent Cloud should support instance start/stop');
assertSame(true, $caps['security_group_manage'], 'Tencent Cloud should support security groups');
assertSame(false, $caps['traffic_monitor'], 'Tencent Cloud traffic is out of scope');

$instance = [
    'InstanceId' => 'ins-1',
    'InstanceName' => 'tx-web',
    'InstanceState' => 'RUNNING',
    'PublicIpAddresses' => ['1.2.3.4'],
    'SecurityGroupIds' => ['sg-1', 'sg-2'],
];

$groups = [[
    'security_group_id' => 'sg-1',
    'security_group_name' => 'web',
    'description' => '',
    'vpc_id' => '',
    'rules' => [],
], [
    'security_group_id' => 'sg-2',
    'security_group_name' => 'admin',
    'description' => '',
    'vpc_id' => '',
    'rules' => [],
]];

$context = $provider->buildContextFromParts(['instance_id' => 'ins-1'], $instance, $groups);
assertSame('success', $context['discoveryStatus'], 'context status');
assertSame('tx-web', $context['instanceName'], 'instance name');
assertSame('Running', $context['instanceStatus'], 'status mapping');
assertSame('1.2.3.4', $context['publicIp'], 'public ip');
assertSame(2, $context['securityGroupCount'], 'security group count');
assertSame(['web', 'admin'], $context['securityGroupNames'], 'security group names');

$rule = $provider->normalizePolicy([
    'PolicyIndex' => 3,
    'Protocol' => 'TCP',
    'Port' => '80',
    'CidrBlock' => '0.0.0.0/0',
    'Action' => 'ACCEPT',
    'PolicyDescription' => 'http',
]);
assertSame('3', $rule['security_group_rule_id'], 'policy index should become rule id');
assertSame('TCP', $rule['ip_protocol'], 'protocol');
assertSame('80/80', $rule['port_range'], 'single port normalization');
assertSame('0.0.0.0/0', $rule['source_cidr_ip'], 'source cidr');

pass('TencentCloudProvider normalizers');
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/TencentCloudProviderTest.php'
```

Expected: FAIL because `TencentCloudProvider.php` does not exist.

- [ ] **Step 3: Create Tencent Cloud provider class**

Create `providers/TencentCloudProvider.php` with this structure:

```php
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
use TencentCloud\Cvm\V20170312\Models\DescribeSecurityGroupPoliciesRequest;
use TencentCloud\Cvm\V20170312\Models\AuthorizeSecurityGroupIngressRequest;
use TencentCloud\Cvm\V20170312\Models\RevokeSecurityGroupIngressRequest;

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
        $names = $this->collectSecurityGroupNames($groups);

        return $this->buildSuccessInstanceContext($account, [
            'instanceId' => $instance['InstanceId'] ?? ($account['instance_id'] ?? ''),
            'instanceName' => $instance['InstanceName'] ?? '',
            'instanceStatus' => $this->mapTencentStatus($instance['InstanceState'] ?? ''),
            'publicIp' => $publicIps[0] ?? '',
            'securityGroups' => $groups,
            'securityGroupCount' => count($groups),
            'securityGroupNames' => $names,
        ]);
    }

    public function normalizePolicy(array $policy): array
    {
        $protocol = strtoupper((string) ($policy['Protocol'] ?? 'TCP'));
        $port = trim((string) ($policy['Port'] ?? ''));
        $portRange = $this->normalizeTencentPort($protocol, $port);

        return [
            'security_group_rule_id' => (string) ($policy['PolicyIndex'] ?? ''),
            'ip_protocol' => $protocol,
            'port_range' => $portRange,
            'source_cidr_ip' => $policy['CidrBlock'] ?? '0.0.0.0/0',
            'source_display' => $policy['CidrBlock'] ?? '0.0.0.0/0',
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
        $client = $this->buildClient($account);
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
        $client = $this->buildClient($account);
        $request = new AuthorizeSecurityGroupIngressRequest();
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
        $client->AuthorizeSecurityGroupIngress($request);
        return true;
    }

    public function deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)
    {
        $client = $this->buildClient($account);
        $request = new RevokeSecurityGroupIngressRequest();
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
        $client->RevokeSecurityGroupIngress($request);
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

    private function buildClient(array $account): CvmClient
    {
        $credential = new Credential($account['access_key_id'], $account['access_key_secret']);
        $httpProfile = new HttpProfile();
        $httpProfile->setEndpoint('cvm.tencentcloudapi.com');
        $clientProfile = new ClientProfile();
        $clientProfile->setHttpProfile($httpProfile);
        return new CvmClient($credential, $account['region_id'], $clientProfile);
    }

    private function fetchInstance(array $account): array
    {
        $request = new DescribeInstancesRequest();
        $request->fromJsonString(json_encode(['InstanceIds' => [$account['instance_id']]]));
        $response = $this->buildClient($account)->DescribeInstances($request);
        $payload = json_decode($response->toJsonString(), true);
        $instances = $payload['InstanceSet'] ?? [];
        if (empty($instances[0])) {
            throw new Exception('腾讯云未找到指定实例');
        }
        return $instances[0];
    }

    private function loadSecurityGroups(array $account, array $groupIds): array
    {
        $groups = [];
        foreach ($groupIds as $groupId) {
            $groups[] = $this->loadSecurityGroupPolicies($account, (string) $groupId);
        }
        return $groups;
    }

    private function loadSecurityGroupPolicies(array $account, string $groupId): array
    {
        $request = new DescribeSecurityGroupPoliciesRequest();
        $request->fromJsonString(json_encode(['SecurityGroupId' => $groupId]));
        $response = $this->buildClient($account)->DescribeSecurityGroupPolicies($request);
        $payload = json_decode($response->toJsonString(), true);
        $ingress = $payload['SecurityGroupPolicySet']['Ingress'] ?? [];

        $rules = [];
        foreach ($ingress as $policy) {
            $rules[] = $this->normalizePolicy($policy);
        }

        return [
            'security_group_id' => $groupId,
            'security_group_name' => $groupId,
            'description' => '',
            'vpc_id' => '',
            'rules' => $rules,
        ];
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
        if ($protocol === 'ICMP') {
            return '-1/-1';
        }
        if (strpos($port, '-') !== false) {
            [$start, $end] = explode('-', $port, 2);
            return trim($start) . '/' . trim($end);
        }
        if (strpos($port, '/') !== false) {
            return $port;
        }
        return $port === '' ? '-1/-1' : $port . '/' . $port;
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
```

- [ ] **Step 4: Register provider**

In `providers/ProviderFactory.php`, add:

```php
require_once __DIR__ . '/TencentCloudProvider.php';
```

Inside the constructor:

```php
$tencentProvider = new TencentCloudProvider();
$this->providers[$tencentProvider->getProviderKey()] = $tencentProvider;
```

- [ ] **Step 5: Run the test**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/TencentCloudProviderTest.php'
```

Expected:

```text
PASS: TencentCloudProvider normalizers
```

- [ ] **Step 6: Commit**

```bash
git add providers/TencentCloudProvider.php providers/ProviderFactory.php tests/TencentCloudProviderTest.php
git commit -m "feat: add tencent cloud provider"
```

---

### Task 5: Add AWS Provider Normalizers with Tests

**Files:**
- Create: `providers/AwsProvider.php`
- Modify: `providers/ProviderFactory.php`
- Create: `tests/AwsProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/AwsProviderTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/AwsProviderTest.php'
```

Expected: FAIL because `AwsProvider.php` does not exist.

- [ ] **Step 3: Create AWS provider class**

Create `providers/AwsProvider.php`:

```php
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
```

- [ ] **Step 4: Register AWS provider**

In `providers/ProviderFactory.php`, add:

```php
require_once __DIR__ . '/AwsProvider.php';
```

Inside the constructor:

```php
$awsProvider = new AwsProvider();
$this->providers[$awsProvider->getProviderKey()] = $awsProvider;
```

- [ ] **Step 5: Run the test**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/AwsProviderTest.php'
```

Expected:

```text
PASS: AwsProvider normalizers
```

- [ ] **Step 6: Commit**

```bash
git add providers/AwsProvider.php providers/ProviderFactory.php tests/AwsProviderTest.php
git commit -m "feat: add aws provider"
```

---

### Task 6: Add GCP Provider Normalizers with Tests

**Files:**
- Create: `providers/GcpProvider.php`
- Modify: `providers/ProviderFactory.php`
- Create: `tests/GcpProviderTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/GcpProviderTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/GcpProviderTest.php'
```

Expected: FAIL because `GcpProvider.php` does not exist.

- [ ] **Step 3: Create GCP provider class**

Create `providers/GcpProvider.php`:

```php
<?php

require_once __DIR__ . '/CloudProviderInterface.php';
require_once __DIR__ . '/CloudInstanceContextInterface.php';
require_once __DIR__ . '/InstanceContextHelpers.php';

use Google\Cloud\Compute\V1\Allowed;
use Google\Cloud\Compute\V1\Firewall;
use Google\Cloud\Compute\V1\FirewallsClient;
use Google\Cloud\Compute\V1\InstancesClient;

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
        $protocol = strtoupper((string) ($allowed['IPProtocol'] ?? $allowed['ipProtocol'] ?? 'tcp'));
        $ports = $allowed['ports'] ?? [];
        $port = $ports[0] ?? '-1';
        $portRange = strpos((string) $port, '-') !== false
            ? str_replace('-', '/', (string) $port)
            : ((string) $port === '-1' ? '-1/-1' : $port . '/' . $port);

        $sourceRanges = $rule['sourceRanges'] ?? ['0.0.0.0/0'];
        $source = $sourceRanges[0] ?? '0.0.0.0/0';

        return [
            'security_group_rule_id' => (string) ($rule['name'] ?? ''),
            'ip_protocol' => $protocol,
            'port_range' => $portRange,
            'source_cidr_ip' => $source,
            'source_display' => $source,
            'description' => $rule['description'] ?? '',
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
        $extra = $this->decodeExtraConfig($account);
        $client = $this->buildInstancesClient($account);
        $project = $account['project_id'];
        $zone = $extra['zone'] ?? $account['region_id'];
        $instance = $account['instance_id'];
        $action = strtolower(trim($action));

        if ($action === 'start') {
            $client->start($instance, $project, $zone);
            return true;
        }

        if ($action === 'stop') {
            $client->stop($instance, $project, $zone);
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
        $network = $extra['network'] ?? 'default';

        $allowed = new Allowed([
            'IPProtocol' => strtolower($rule['ip_protocol'] ?? 'tcp'),
            'ports' => $this->portsForGcp($rule['port_range'] ?? '-1/-1'),
        ]);

        $firewall = new Firewall([
            'name' => $name,
            'description' => $rule['description'] ?? '',
            'direction' => 'INGRESS',
            'network' => $network,
            'sourceRanges' => [$rule['source_cidr_ip'] ?? '0.0.0.0/0'],
            'allowed' => [$allowed],
            'targetTags' => $this->parseTags($extra['target_tags'] ?? ''),
        ]);

        $this->buildFirewallsClient($account)->insert($firewall, $account['project_id']);
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

        $this->buildFirewallsClient($account)->delete($ruleName, $account['project_id']);
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
        $instance = $this->buildInstancesClient($account)->get($account['instance_id'], $account['project_id'], $extra['zone'] ?? $account['region_id']);
        return json_decode($instance->serializeToJsonString(), true);
    }

    private function listFirewallRules(array $account, array $instance): array
    {
        $extra = $this->decodeExtraConfig($account);
        $prefix = $extra['firewall_rule_prefix'] ?? 'cdt-monitor-';
        $client = $this->buildFirewallsClient($account);
        $rules = [];

        foreach ($client->list($account['project_id']) as $firewall) {
            $rule = json_decode($firewall->serializeToJsonString(), true);
            $name = (string) ($rule['name'] ?? '');
            if (strpos($name, $prefix) === 0 || $this->ruleTargetsInstance($rule, $instance, $extra)) {
                $rules[] = $rule;
            }
        }

        return $rules;
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
        foreach ($instance['networkInterfaces'] ?? [] as $interface) {
            foreach ($interface['accessConfigs'] ?? [] as $config) {
                if (!empty($config['natIP'])) {
                    return $config['natIP'];
                }
            }
        }
        return '';
    }

    private function extractNetworkName(array $instance): string
    {
        $network = $instance['networkInterfaces'][0]['network'] ?? '';
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
        $ruleTags = $rule['targetTags'] ?? [];
        return count(array_intersect($configuredTags, $ruleTags)) > 0;
    }
}
```

- [ ] **Step 4: Register GCP provider**

In `providers/ProviderFactory.php`, add:

```php
require_once __DIR__ . '/GcpProvider.php';
```

Inside the constructor:

```php
$gcpProvider = new GcpProvider();
$this->providers[$gcpProvider->getProviderKey()] = $gcpProvider;
```

- [ ] **Step 5: Run the test**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/GcpProviderTest.php'
```

Expected:

```text
PASS: GcpProvider normalizers
```

- [ ] **Step 6: Commit**

```bash
git add providers/GcpProvider.php providers/ProviderFactory.php tests/GcpProviderTest.php
git commit -m "feat: add gcp provider"
```

---

### Task 7: Extend Frontend Provider Metadata and Config Fields

**Files:**
- Modify: `template.html`
- Create: `tests/FrontendProviderMetadataTest.php`

- [ ] **Step 1: Write the failing static frontend test**

Create `tests/FrontendProviderMetadataTest.php`:

```php
<?php

require_once __DIR__ . '/bootstrap.php';

$template = file_get_contents(__DIR__ . '/../template.html');

foreach (['tencentcloud', 'aws', 'gcp'] as $provider) {
    assertContains($provider, $template, "{$provider} should appear in frontend template");
}

assertContains('<option value="tencentcloud">腾讯云</option>', $template, 'Tencent option');
assertContains('<option value="aws">AWS</option>', $template, 'AWS option');
assertContains('<option value="gcp">GCP</option>', $template, 'GCP option');
assertContains('service_account_json', $template, 'GCP service account JSON field');
assertContains('getSecurityRuleLabel', $template, 'provider-specific security rule label helper');

pass('Frontend provider metadata');
```

- [ ] **Step 2: Run the test to verify it fails**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/FrontendProviderMetadataTest.php'
```

Expected: FAIL because new provider metadata and GCP fields are not present.

- [ ] **Step 3: Add provider options**

In the provider select, change:

```html
<option value="aliyun">阿里云</option>
<option value="huaweicloud">华为云</option>
```

to:

```html
<option value="aliyun">阿里云</option>
<option value="huaweicloud">华为云</option>
<option value="tencentcloud">腾讯云</option>
<option value="aws">AWS</option>
<option value="gcp">GCP</option>
```

- [ ] **Step 4: Add provider metadata and capabilities**

In `providerMetaMap`, add:

```js
tencentcloud: {
    label: '腾讯云',
    trafficLabel: '实例运维',
    badgeClass: 'bg-blue-50 text-blue-600 border-blue-200'
},
aws: {
    label: 'AWS',
    trafficLabel: '实例运维',
    badgeClass: 'bg-yellow-50 text-yellow-700 border-yellow-200'
},
gcp: {
    label: 'GCP',
    trafficLabel: '实例运维',
    badgeClass: 'bg-emerald-50 text-emerald-600 border-emerald-200'
}
```

In `providerCapabilityMap`, add the capability arrays from the design spec for `tencentcloud`, `aws`, and `gcp`.

- [ ] **Step 5: Add GCP extra config defaults**

In the default account object created by `addAccount()`, add:

```js
extraConfig: {
    zone: '',
    network: 'default',
    target_tags: '',
    firewall_rule_prefix: 'cdt-monitor-',
    service_account_json: ''
}
```

In loaded config normalization, add:

```js
if (typeof acc.extraConfig === 'undefined' || acc.extraConfig === null) {
    acc.extraConfig = {};
}
acc.extraConfig = {
    zone: acc.extraConfig.zone || '',
    network: acc.extraConfig.network || 'default',
    target_tags: acc.extraConfig.target_tags || '',
    firewall_rule_prefix: acc.extraConfig.firewall_rule_prefix || 'cdt-monitor-',
    service_account_json: acc.extraConfig.service_account_json || ''
};
```

- [ ] **Step 6: Add GCP config fields**

In the account form below shared fields, add:

```html
<div v-if="acc.cloudProvider === 'gcp'" class="space-y-1">
    <label class="text-xs font-medium text-gray-500 ml-2">GCP Zone</label>
    <input v-model.trim="acc.extraConfig.zone" placeholder="asia-east1-a"
        class="w-full glass-input rounded-xl px-4 py-2 text-sm font-mono">
</div>
<div v-if="acc.cloudProvider === 'gcp'" class="space-y-1">
    <label class="text-xs font-medium text-gray-500 ml-2">Service Account JSON</label>
    <textarea v-model.trim="acc.extraConfig.service_account_json" rows="4"
        class="w-full glass-input rounded-xl px-4 py-2 text-xs font-mono"
        placeholder='{"type":"service_account","project_id":"..."}'></textarea>
</div>
<div v-if="acc.cloudProvider === 'gcp'" class="space-y-1">
    <label class="text-xs font-medium text-gray-500 ml-2">Network</label>
    <input v-model.trim="acc.extraConfig.network" placeholder="default"
        class="w-full glass-input rounded-xl px-4 py-2 text-sm font-mono">
</div>
<div v-if="acc.cloudProvider === 'gcp'" class="space-y-1">
    <label class="text-xs font-medium text-gray-500 ml-2">Target Tags</label>
    <input v-model.trim="acc.extraConfig.target_tags" placeholder="web,ssh"
        class="w-full glass-input rounded-xl px-4 py-2 text-sm font-mono">
</div>
<div v-if="acc.cloudProvider === 'gcp'" class="space-y-1">
    <label class="text-xs font-medium text-gray-500 ml-2">Firewall Prefix</label>
    <input v-model.trim="acc.extraConfig.firewall_rule_prefix" placeholder="cdt-monitor-"
        class="w-full glass-input rounded-xl px-4 py-2 text-sm font-mono">
</div>
```

- [ ] **Step 7: Add provider-specific security labels**

Add JS helper:

```js
const getSecurityRuleLabel = (providerKey) => providerKey === 'gcp' ? '防火墙规则' : '安全组';
```

Use it in the modal title and empty states:

```html
{{ getSecurityRuleLabel(selectedAccount?.cloudProvider) }}
```

- [ ] **Step 8: Run frontend static test**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/FrontendProviderMetadataTest.php'
```

Expected:

```text
PASS: Frontend provider metadata
```

- [ ] **Step 9: Commit**

```bash
git add template.html tests/FrontendProviderMetadataTest.php
git commit -m "feat: add frontend provider metadata"
```

---

### Task 8: Add Factory and Frontend Config Integration Tests

**Files:**
- Create: `tests/ProviderFactoryMultiCloudTest.php`
- Create: `tests/ConfigManagerMultiProviderValidationTest.php`

- [ ] **Step 1: Write factory test**

Create `tests/ProviderFactoryMultiCloudTest.php`:

```php
<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../providers/ProviderFactory.php';

$factory = new ProviderFactory();

foreach (['aliyun', 'huaweicloud', 'tencentcloud', 'aws', 'gcp'] as $providerKey) {
    $provider = $factory->getProvider($providerKey);
    assertSame($providerKey, $provider->getProviderKey(), "{$providerKey} should resolve from factory");
}

pass('ProviderFactory multi-cloud registration');
```

- [ ] **Step 2: Write validation test**

Create `tests/ConfigManagerMultiProviderValidationTest.php`:

```php
<?php

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../ConfigManager.php';

function basePayload(): array
{
    return [
        'admin_password' => 'pass',
        'traffic_threshold' => 95,
        'enable_schedule_email' => false,
        'shutdown_mode' => 'KeepCharging',
        'threshold_action' => 'stop_and_notify',
        'keep_alive' => false,
        'api_interval' => 600,
        'enable_billing' => false,
        'Notification' => [],
        'Accounts' => [],
    ];
}

function accountFor(string $provider): array
{
    $account = [
        'cloudProvider' => $provider,
        'AccessKeyId' => 'ak',
        'AccessKeySecret' => 'sk',
        'regionId' => 'ap-region-1',
        'instanceId' => 'instance-1',
        'projectId' => '',
        'securityGroupId' => '',
        'maxTraffic' => 0,
        'schedule' => ['enabled' => false, 'startTime' => '', 'stopTime' => ''],
        'remark' => '',
        'siteType' => 'china',
        'apiProxy' => ['enabled' => false, 'host' => '', 'port' => '', 'username' => '', 'password' => ''],
        'extraConfig' => [],
    ];

    if ($provider === 'gcp') {
        $account['AccessKeyId'] = '';
        $account['AccessKeySecret'] = '';
        $account['projectId'] = 'project-1';
        $account['regionId'] = 'asia-east1-a';
        $account['instanceId'] = 'gcp-vm';
        $account['extraConfig'] = [
            'zone' => 'asia-east1-a',
            'network' => 'default',
            'target_tags' => 'web',
            'firewall_rule_prefix' => 'cdt-monitor-',
            'service_account_json' => '{"type":"service_account","project_id":"project-1"}',
        ];
    }

    return $account;
}

$dbPath = createTempDbPath('config-multi-provider');
$manager = new ConfigManager(new Database($dbPath));

$payload = basePayload();
$payload['Accounts'] = [
    accountFor('tencentcloud'),
    accountFor('aws'),
    accountFor('gcp'),
];

assertSame(true, $manager->updateConfig($payload), 'valid multi-provider payload should save');
assertSame(3, count($manager->getAccounts()), 'three accounts should persist');

$invalid = basePayload();
$badAws = accountFor('aws');
$badAws['instanceId'] = '';
$invalid['Accounts'] = [$badAws];
assertSame(false, $manager->updateConfig($invalid), 'aws without instance should fail');

pass('ConfigManager multi-provider validation');
```

- [ ] **Step 3: Run integration tests**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'php tests/ProviderFactoryMultiCloudTest.php && php tests/ConfigManagerMultiProviderValidationTest.php'
```

Expected:

```text
PASS: ProviderFactory multi-cloud registration
PASS: ConfigManager multi-provider validation
```

- [ ] **Step 4: Commit**

```bash
git add tests/ProviderFactoryMultiCloudTest.php tests/ConfigManagerMultiProviderValidationTest.php
git commit -m "test: cover multi-cloud provider integration"
```

---

### Task 9: Update Documentation

**Files:**
- Modify: `README.MD`
- Modify: `MULTI_CLOUD_PLAN.md`

- [ ] **Step 1: Update provider capability table**

In `README.MD`, replace the provider table rows for Tencent, AWS, and GCP with:

```markdown
| 腾讯云 (Tencent Cloud) | | ✅ | ✅ | | 第一阶段支持实例运维，不含流量/账单 |
| AWS | | ✅ | ✅ | | 第一阶段支持 EC2 运维，不含流量/账单 |
| GCP | | ✅ | ✅ 防火墙规则 | | 第一阶段支持 Compute Engine 运维，不含流量/账单 |
```

- [ ] **Step 2: Add account field notes**

Add this section after the Huawei Cloud account configuration section:

```markdown
### 腾讯云 / AWS / GCP 账号配置说明

新增云厂商第一阶段聚焦实例运维能力：实例状态、手动开关机、安全组或防火墙规则管理。流量监控、账单摘要、阈值熔断和定时自动开关机不在第一阶段范围内。

- 腾讯云：填写 SecretId、SecretKey、Region、CVM Instance ID。
- AWS：填写 Access Key ID、Secret Access Key、Region、EC2 Instance ID。
- GCP：填写 Project ID、Zone、Instance Name、Service Account JSON。GCP 使用防火墙规则模型，新增规则会使用 `cdt-monitor-` 前缀，删除时只允许删除此前缀下的规则。
```

- [ ] **Step 3: Update implementation status in `MULTI_CLOUD_PLAN.md`**

Add a new section near the top:

```markdown
## 2026-05-23 实施状态

当前多云路线新增腾讯云、AWS、GCP 的实例运维第一阶段：

- 腾讯云：实例状态、开关机、安全组规则
- AWS：EC2 状态、开关机、安全组规则
- GCP：Compute Engine 状态、开关机、防火墙规则

流量监控、账单摘要、阈值熔断和定时自动开关机保持为下一轮能力，不与第一阶段混合交付。
```

- [ ] **Step 4: Commit**

```bash
git add README.MD MULTI_CLOUD_PLAN.md
git commit -m "docs: document new instance operation providers"
```

---

### Task 10: Full Verification

**Files:**
- Verify only

- [ ] **Step 1: Run all project tests**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'for t in tests/*.php; do php "$t" || exit 1; done'
```

Expected: every test prints `PASS:` and the command exits with code 0.

- [ ] **Step 2: Run PHP syntax checks**

Run:

```bash
docker run --rm --entrypoint sh \
  -v /home/nax/CDT-Monitor:/app \
  -w /app cdt-monitor-local:latest \
  -lc 'for f in *.php providers/*.php tests/*.php; do php -l "$f" || exit 1; done'
```

Expected: every file prints `No syntax errors detected`.

- [ ] **Step 3: Build Docker image**

Run:

```bash
docker build -t cdt-monitor-multicloud-test /home/nax/CDT-Monitor
```

Expected: image builds successfully.

- [ ] **Step 4: Smoke test HTTP init endpoint**

Run:

```bash
docker run -d --rm --name cdt-monitor-multicloud-test -p 43212:80 \
  -v /home/nax/CDT-Monitor/data:/var/www/html/data \
  cdt-monitor-multicloud-test
sleep 5
curl -fsS http://127.0.0.1:43212/index.php?action=check_init
docker stop cdt-monitor-multicloud-test
```

Expected:

```json
{"initialized":true}
```

If the local data directory is not initialized in the execution environment, expected output is valid JSON with `initialized` set to `false`.

- [ ] **Step 5: Confirm git status**

Run:

```bash
git -c safe.directory=/home/nax/CDT-Monitor status --short --branch
```

Expected: branch is clean after all commits.
