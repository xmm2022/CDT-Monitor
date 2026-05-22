# Multi-Cloud Instance Operations Design

**Date:** 2026-05-23

**Project:** `CDT-Monitor`

## Goal

Add Tencent Cloud, AWS, and GCP as instance-operations providers. The first release for these providers focuses on manual operations and security rule management, not traffic metering, billing, or automated threshold shutdown.

## Scope

Initial provider priority:

1. Tencent Cloud
2. AWS
3. GCP

Initial capabilities for each new provider:

- Account configuration
- Instance context discovery
- Instance status display
- Manual start and stop
- Security rule listing
- Security rule creation
- Security rule deletion where the provider can identify a safe rule target

Out of scope for this round:

- Traffic monitoring
- Billing summaries
- Threshold-triggered shutdown
- Scheduled automatic start/stop for new providers
- Multi-instance discovery under one account
- Cross-region aggregation

## Current Architecture Fit

The project already has a provider abstraction:

- `providers/CloudProviderInterface.php`
- `providers/AliyunProvider.php`
- `providers/HuaweiCloudProvider.php`
- `providers/ProviderFactory.php`

`AliyunTrafficCheck.php` still contains cloud-specific branching, especially around Huawei Cloud instance context. The new design keeps the provider interface intact for compatibility, but promotes instance-context support from a Huawei-only convention into a provider-level optional capability.

The frontend already has a provider metadata map and capability map in `template.html`. This should be extended rather than replaced.

## Account Model

Existing columns continue to serve common provider fields:

- `cloud_provider`
- `access_key_id`
- `access_key_secret`
- `region_id`
- `instance_id`
- `project_id`
- `security_group_id`
- `max_traffic`
- `remark`
- `api_proxy_*`

Add one extensibility column:

- `extra_config TEXT DEFAULT '{}'`

`extra_config` stores provider-specific settings that do not fit the shared model.

Provider-specific field mapping:

| Provider | Shared Fields | Extra Config |
|---|---|---|
| Tencent Cloud | `access_key_id`, `access_key_secret`, `region_id`, `instance_id`, `security_group_id` | none required in first release |
| AWS | `access_key_id`, `access_key_secret`, `region_id`, `instance_id`, `security_group_id` | none required in first release |
| GCP | `project_id`, `region_id`, `instance_id` | `service_account_json`, `zone`, `network`, `target_tags`, `firewall_rule_prefix` |

For GCP, `region_id` may hold the zone for UI consistency during the first implementation, but `extra_config.zone` is the canonical field used by the provider. If both exist, `extra_config.zone` wins.

## Provider Keys

Provider keys:

- `aliyun`
- `huaweicloud`
- `tencentcloud`
- `aws`
- `gcp`

Labels:

- 阿里云
- 华为云
- 腾讯云
- AWS
- GCP

## Capabilities

Tencent Cloud and AWS:

```php
[
    'traffic_monitor' => false,
    'security_group_manage' => true,
    'instance_start_stop' => true,
    'stop_charging' => false,
    'billing_summary' => false,
    'schedule_manage' => false,
    'per_account_proxy' => true,
    'site_type_select' => false,
    'region_picker' => true,
]
```

GCP:

```php
[
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
]
```

`schedule_manage` remains false for new providers until manual operations have been validated against real provider behavior.

## Instance Context Contract

Providers that support instance operations expose:

```php
public function describeAccountContext(array $account): array;
```

Returned shape:

```php
[
    'instanceId' => 'i-...',
    'instanceName' => 'web-1',
    'instanceStatus' => 'Running',
    'publicIp' => '203.0.113.10',
    'securityGroups' => [],
    'securityGroupCount' => 0,
    'securityGroupNames' => [],
    'discoveryStatus' => 'success',
    'discoveryMode' => 'instance',
    'discoveryMessage' => '',
    'usingFallbackSecurityGroup' => false,
    'fallbackSecurityGroupId' => '',
    'trafficDataAvailable' => false,
    'trafficUsedGb' => null,
    'trafficError' => '',
]
```

Status values:

- `success`: instance lookup and security context loaded
- `fallback`: a compatible security object was loaded without full instance context
- `incomplete`: required account fields are missing
- `error`: provider API returned an error or the response could not be interpreted

Status normalization:

- `Running`
- `Stopped`
- `Starting`
- `Stopping`
- `Pending`
- `Unknown`

## Security Rule Contract

All providers continue to use the existing security-group endpoint shape:

- `getInstanceSecurityGroups(array $account)`
- `addSecurityGroupRule(array $account, string $securityGroupId, array $rule)`
- `deleteSecurityGroupRule(array $account, string $securityGroupId, array $rule)`

The normalized group shape remains:

```php
[
    'security_group_id' => 'sg-...',
    'security_group_name' => 'web',
    'description' => '',
    'vpc_id' => '',
    'rules' => [
        [
            'security_group_rule_id' => 'rule-id-or-provider-stable-key',
            'ip_protocol' => 'TCP',
            'port_range' => '80/80',
            'source_cidr_ip' => '0.0.0.0/0',
            'source_display' => '0.0.0.0/0',
            'description' => 'http',
            'direction' => 'ingress',
        ],
    ],
]
```

Tencent Cloud and AWS map naturally to this contract.

GCP maps firewall rules into this contract with these constraints:

- The group list represents firewall-rule collections for the selected network or instance target.
- New rules created by CDT-Monitor use a prefix such as `cdt-monitor-`.
- Deletion is allowed only when the selected rule has a provider-stable name and matches the configured prefix or exact normalized rule identity.
- The UI label for GCP should say “防火墙规则” instead of “安全组”.

## Tencent Cloud Design

SDK:

- Composer package: `tencentcloud/cvm`

Required account fields:

- SecretId: `access_key_id`
- SecretKey: `access_key_secret`
- Region: `region_id`
- Instance ID: `instance_id`

Primary APIs:

- `DescribeInstances`
- `StartInstances`
- `StopInstances`
- `DescribeSecurityGroups`
- `DescribeSecurityGroupPolicies`
- `AuthorizeSecurityGroupIngress`
- `RevokeSecurityGroupIngress`

Behavior:

- `describeAccountContext()` calls CVM instance detail first.
- It extracts instance name, status, public IP, and associated security group IDs.
- If no security group is attached, it returns `success` with zero groups.
- Rule deletion uses enough normalized policy fields to identify the ingress policy.

## AWS Design

SDK:

- Composer package: `aws/aws-sdk-php`

Required account fields:

- Access Key ID: `access_key_id`
- Secret Access Key: `access_key_secret`
- Region: `region_id`
- Instance ID: `instance_id`
- Optional Security Group ID: `security_group_id`

Primary APIs:

- `DescribeInstances`
- `StartInstances`
- `StopInstances`
- `DescribeSecurityGroups`
- `AuthorizeSecurityGroupIngress`
- `RevokeSecurityGroupIngress`

Behavior:

- `describeAccountContext()` resolves the instance first and reads attached security groups.
- Security group rules use AWS security group rule IDs when present.
- If rule IDs are unavailable, deletion falls back to matching protocol, port range, and CIDR.

## GCP Design

SDK:

- Composer package: `google/cloud-compute`

Required account fields:

- Project ID: `project_id`
- Zone: `extra_config.zone`
- Instance name: `instance_id`
- Service account JSON: `extra_config.service_account_json`

Optional fields:

- `extra_config.network`
- `extra_config.target_tags`
- `extra_config.firewall_rule_prefix`

Primary clients:

- `InstancesClient`
- `FirewallsClient`
- `ZoneOperationsClient`
- `GlobalOperationsClient`

Behavior:

- `describeAccountContext()` loads the Compute Engine instance by project, zone, and instance name.
- Public IP is read from access configs on network interfaces.
- Firewall rule listing filters by target tags when configured. If no target tags are configured, it falls back to the instance network and CDT-Monitor prefix.
- Start and stop operations are accepted once the API call returns an operation object; the UI will reflect final state on the next refresh.
- GCP firewall rules are global resources, not instance-local security groups. The UI must make this clear by label.

## Frontend Design

Configuration page:

- Add provider options: 腾讯云, AWS, GCP.
- Keep shared fields for Tencent Cloud and AWS.
- For GCP, show:
  - Project ID
  - Zone
  - Instance name
  - Service Account JSON
  - Network
  - Target tags
  - Firewall rule prefix

Status cards:

- For new providers, the primary metric area shows instance operations context, not traffic.
- Display:
  - Instance name or ID
  - Public IP
  - Instance status
  - Security group or firewall rule count
  - Last refresh time

Port management modal:

- Tencent Cloud and AWS keep “安全组”.
- GCP uses “防火墙规则”.
- Add/delete forms keep current normalized fields: protocol, port range, source CIDR, description.

## Validation

Config validation must reject incomplete accounts:

- Tencent Cloud: AK, SK, region, instance ID
- AWS: AK, SK, region, instance ID
- GCP: project ID, zone, instance name, service account JSON

The validation layer should parse `extra_config` as JSON. Invalid JSON returns a user-facing configuration error.

## Testing Strategy

Unit-style tests should avoid real cloud API calls. They validate:

- Provider capability maps
- Config validation for each provider
- `extra_config` persistence and round trip
- Context builders from synthetic provider response arrays
- Status normalization
- Security rule normalization
- Frontend config payload shape

Real cloud API calls remain manual verification because credentials and cloud resources are environment-specific.

## Deployment

Composer dependencies are added in `composer.json`.

Docker build should continue using the builder stage. Dependency trimming should be provider-aware:

- Keep only required Huawei Cloud services already used.
- Avoid deleting Tencent Cloud CVM classes.
- Keep AWS EC2 client support.
- Keep Google Compute client support.

If image size grows too much, optimization should happen after the providers pass functional tests. Do not hand-roll REST signing purely for image size.

## Rollout

Implement in this order:

1. Shared schema, config, context helpers, and tests.
2. Tencent Cloud provider and UI.
3. AWS provider and UI.
4. GCP provider and UI.
5. README capability table and deployment notes.

Each step should leave Aliyun and Huawei Cloud behavior intact.
