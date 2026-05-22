# Multi-Cloud Plan

## 2026-05-23 实施状态

当前多云路线新增腾讯云、AWS、GCP 的实例运维第一阶段：

- 腾讯云：实例状态、开关机、安全组规则
- AWS：EC2 状态、开关机、安全组规则
- GCP：Compute Engine 状态、开关机、防火墙规则

流量监控、账单摘要、阈值熔断和定时自动开关机保持为下一轮能力，不与第一阶段混合交付。

## 目标

在同一个面板内管理多个云厂商账号，但不强求所有厂商功能完全一致。

核心原则：

- 统一入口：同一个登录页、实例列表页、配置页。
- 能力驱动：按账号所属云厂商显示不同功能。
- 渐进改造：先把阿里云逻辑抽象出来，再接华为云。
- 向后兼容：现有阿里云账号迁移后仍可继续使用。

## 当前代码结构

当前项目是单云架构，阿里云逻辑集中在：

- `AliyunService.php`：阿里云 API 调用
- `AliyunTrafficCheck.php`：业务编排、日志、前端数据组装
- `ConfigManager.php`：配置读写
- `Database.php`：SQLite 结构
- `template.html`：前端页面
- `index.php`：接口路由

问题在于：

- 业务类名和服务类名都绑定阿里云
- 前端字段默认假设所有账号都有阿里云能力
- 数据表没有明确标识云厂商

## 目标架构

### 1. 账号模型

给 `accounts` 表增加：

- `cloud_provider`：`aliyun` / `huaweicloud`
- `extra_config`：JSON，存放厂商特有字段

建议保留现有常用字段，避免第一阶段大迁移：

- `access_key_id`
- `access_key_secret`
- `region_id`
- `instance_id`
- `remark`

对于不同云厂商的额外字段放进 `extra_config`，例如：

- 阿里云：`site_type`、`shutdown_mode`
- 华为云：`project_id`、`iam_endpoint`、`ecs_endpoint`、`vpc_endpoint`

### 2. Provider 抽象

新增统一接口：

```php
interface CloudProviderInterface
{
    public function getProviderKey(): string;
    public function getCapabilities(array $account): array;
    public function getInstanceStatus(array $account): string;
    public function startInstance(array $account): bool;
    public function stopInstance(array $account, array $options = []): bool;
    public function getTrafficStats(array $account): array;
    public function listSecurityGroups(array $account): array;
    public function addSecurityGroupRule(array $account, array $rule): bool;
    public function deleteSecurityGroupRule(array $account, array $rule): bool;
    public function getBillingSummary(array $account): array;
}
```

第一阶段实现两个类：

- `providers/AliyunProvider.php`
- `providers/HuaweiCloudProvider.php`

配一个工厂：

- `providers/ProviderFactory.php`

### 3. 业务编排层

把 `AliyunTrafficCheck.php` 逐步改为中立命名，例如：

- `CloudControlService.php`

它不直接调用阿里云 API，而是：

1. 根据账号的 `cloud_provider` 取 provider
2. 调用 provider 的统一能力接口
3. 组装前端返回数据
4. 写日志

### 4. 前端能力驱动

前端不要写死“所有账号都有 CDT 或 StopCharging”，改为按能力显示。

后端给每个账号返回：

```json
{
  "cloud_provider": "aliyun",
  "capabilities": {
    "traffic_monitor": true,
    "security_group_manage": true,
    "instance_start_stop": true,
    "stop_charging": true,
    "billing_summary": true,
    "flow_log": false
  }
}
```

前端据此控制：

- 是否显示流量卡片
- 是否显示端口管理按钮
- 是否显示“节省停机”
- 是否显示账单模块

## 页面设计建议

### 1. 账号配置页

每个账号新增：

- 云厂商选择器
- 厂商专属字段区域

示例：

- 阿里云账号显示：
  - `AccessKey ID`
  - `AccessKey Secret`
  - `Region ID`
  - `Instance ID`
  - `Site Type`

- 华为云账号显示：
  - `AK`
  - `SK`
  - `Project ID`
  - `Region`
  - `ECS Instance ID`
  - `EIP ID` 或 `Public IP`

### 2. 首页实例卡片

卡片顶部显示厂商标签：

- `Alibaba Cloud`
- `Huawei Cloud`

卡片中只显示该账号支持的数据：

- 阿里云：`CDT 流量`
- 华为云：`EIP 出入流量`

### 3. 安全组弹窗

保留同一个“端口管理”入口，但规则字段允许厂商差异。

共同字段：

- 协议
- 端口范围
- 来源地址
- 备注

差异字段由 provider 自己解释。

## 华为云能力映射

### 可直接支持

- 实例状态查询
- 开关机
- 安全组规则查询/新增/删除
- 公网流量监控

### 第一阶段不建议支持

- 复杂 VPC 流日志分析
- NAT/ELB 出口聚合流量
- 所有华为云账单细分维度

## 实施顺序

### Phase 1：抽象阿里云

目标：

- 不增加新云厂商
- 只把现有阿里云代码迁到 provider 架构

改动：

- 新增 `cloud_provider` 字段，默认填 `aliyun`
- 新增 `AliyunProvider`
- `AliyunTrafficCheck` 改成通过 provider 调用

### Phase 2：前端能力驱动

目标：

- 页面支持不同厂商显示不同功能

改动：

- `getStatusForFrontend()` 返回 `cloud_provider` 和 `capabilities`
- 前端按能力显示按钮和模块

### Phase 3：接入华为云安全组

目标：

- 先把“端口管理”打通

原因：

- 这块最接近当前已实现的阿里云能力
- 用户价值直接

### Phase 4：接入华为云流量统计

目标：

- 先支持基于 `EIP/CES` 的流量统计

说明：

- 第一阶段只做实时监控口径
- 不先做账单口径

### Phase 5：账单和更复杂网络能力

可选：

- 华为云账单摘要
- VPC Flow Log
- 多出口流量归因

## 第一阶段最小可实施范围

如果要马上动手，建议先做下面这组最小改造：

1. `accounts` 表增加 `cloud_provider`
2. 新建 `providers/CloudProviderInterface.php`
3. 新建 `providers/AliyunProvider.php`
4. 新建 `providers/ProviderFactory.php`
5. 把 `AliyunTrafficCheck.php` 中直接调 `AliyunService` 的地方改成调 provider
6. 前端账号配置里增加“云厂商”下拉框

这样做完以后：

- 面板仍然只支持阿里云
- 但已经具备接华为云的骨架

## 风险点

- 不同云厂商的实例标识、区域标识、账单口径并不一致
- 安全组规则返回结构可能差异较大
- 如果前端仍按阿里云字段命名，后面会越来越难维护

所以建议：

- 尽快把后端 provider 化
- 前端尽快转成 capability 驱动

## 建议下一步

直接开始 `Phase 1`。

这是后续接华为云的必要前提，也是当前成本最低、回报最高的一步。
