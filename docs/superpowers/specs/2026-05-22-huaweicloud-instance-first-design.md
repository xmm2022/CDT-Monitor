# 华为云实例优先体验优化设计

**日期：** 2026-05-22

**项目：** `CDT-Monitor`

## 背景

当前多云面板已经具备阿里云 provider 抽象、能力驱动前端、华为云第一版安全组管理和单账号 SOCKS5 代理能力。

但华为云当前体验仍然明显落后于阿里云，核心原因不是界面样式，而是产品主对象不一致：

- 阿里云围绕“实例”工作
- 华为云围绕“手填 Security Group ID”工作

这会导致：

- 配置页要求用户理解并手填底层网络对象
- 首页卡片看不出当前账号到底在管理哪台机器
- 端口管理弹窗无法稳定表达“当前正在管理哪个实例的哪些安全组”
- 后续接入真实流量、实例状态、实例级能力时没有统一主线

## 目标

本轮目标是把华为云体验调整为“实例优先”，并尽量与阿里云首页卡片和配置心智对齐。

具体目标：

- 华为云账号以 `Instance ID` 为核心对象
- 默认通过实例自动发现该实例绑定的全部安全组
- 首页卡片在结构和信息层级上与阿里云尽量对齐
- 端口管理弹窗从“单个手填 SG”升级为“实例关联安全组集合”
- 保留现有 `Security Group ID` 链路作为兼容回退，避免旧账号直接失效

## 非目标

本轮不做以下内容：

- 华为云真实公网流量统计
- 华为云账单摘要
- 华为云实例开关机
- 一个账号自动发现多个实例
- 对数据库做大规模 schema 重构

## 方案结论

采用“实例优先，安全组自动发现，手填 SG 退居兼容层”的路线。

核心决策：

- 华为云核心必填字段改为 `AK/SK + Region ID + Project ID + Instance ID`
- `Security Group ID` 保留为兼容字段，可选
- 后端默认按 `Instance ID + Project ID + Region ID` 查询 ECS 实例
- 根据实例返回的安全组列表自动加载可管理安全组
- 首页卡片中心区第一版先显示“可管理安全组数”
- 自动发现失败时再回退到兼容 `Security Group ID`

## 账号模型

这一轮复用现有 `accounts` 表字段，不新增复杂 JSON 结构。

华为云账号字段语义调整如下：

- `access_key_id`：华为云 AK
- `access_key_secret`：华为云 SK
- `region_id`：华为云 Region ID
- `project_id`：必填
- `instance_id`：必填，作为实例主对象
- `security_group_id`：兼容安全组 ID，可选，仅用于回退
- `remark`：备注
- `api_proxy_*`：账号级 SOCKS5 代理

行为规则：

- 新建华为云账号时，`Instance ID` 必填
- 编辑并保存华为云账号时，若缺少 `Instance ID`，保存失败
- 旧账号若没有 `Instance ID`，仍允许读取和展示，但标记为“待升级”或“兼容模式”

## Provider 设计

`providers/HuaweiCloudProvider.php` 从“单个 SG 管理器”升级为“实例上下文发现 + 安全组管理器”。

建议保留现有 `CloudProviderInterface` 的主干结构，避免影响阿里云 provider。

华为云 provider 需要新增或增强的内部能力：

- 查询 ECS 实例基础信息
- 解析实例名称、实例状态、公网 IP
- 从实例信息中提取绑定安全组列表
- 根据安全组列表加载安全组详情和入站规则
- 在发现失败时回退到兼容 `security_group_id`

建议在 provider 内部引入一个实例上下文结果，包含：

- `instanceId`
- `instanceName`
- `instanceStatus`
- `publicIp`
- `securityGroups`
- `discoveryStatus`
- `discoveryMessage`
- `discoveryMode`
- `usingFallbackSecurityGroup`

发现模式定义：

- `instance`：基于实例自动发现成功
- `security_group_fallback`：实例发现失败后回退到兼容安全组
- `incomplete`：配置不完整，无法执行发现

## 首页卡片设计

华为云卡片继续复用当前首页卡片结构，但信息语义调整为与阿里云更对齐。

### 顶部区域

保留：

- 云厂商标签
- Region 标签
- 最近更新时间

### 中心主区

第一版主数字不显示真实流量，改为显示：

- `securityGroupCount`
- 单位为 `组`
- 副标题为 `可管理安全组`

为了复用现有“大数字 + 进度条”结构，华为云卡片仍返回：

- `flow_used`
- `flow_total`
- `percentageOfUse`

但含义重定义为：

- `flow_used = securityGroupCount`
- `flow_total = securityGroupVisualMax`
- `percentageOfUse = securityGroupVisualPercent`

展示映射规则：

- `0 组 -> 0%`
- `1 组 -> 35%`
- `2 组 -> 60%`
- `3 组 -> 80%`
- `4 组及以上 -> 100%`

这样可以在不拆卡片组件的前提下，把华为云卡片视觉层级提升到与阿里云相近的水平。

### 次信息区

华为云卡片补充：

- 实例名称，若无则显示实例 ID
- 公网 IP
- 当前实例状态
- 当前发现模式

### 底部状态

底部继续显示：

- 脱敏后的账号标识
- 当前实例状态

同时新增模式说明：

- `实例发现`
- `兼容 SG`
- `配置待升级`
- `发现失败`

## 前端状态模型

`AliyunTrafficCheck::getStatusForFrontend()` 需要为华为云账号返回更完整的状态字段。

新增字段：

- `instanceName`
- `publicIp`
- `securityGroupCount`
- `securityGroupNames`
- `discoveryStatus`
- `discoveryMessage`
- `discoveryMode`
- `usingFallbackSecurityGroup`
- `fallbackSecurityGroupId`

状态枚举：

- `success`
- `fallback`
- `incomplete`
- `error`

语义：

- `success`：实例自动发现成功
- `fallback`：实例发现失败，但兼容 SG 生效
- `incomplete`：华为云必填配置不完整
- `error`：接口调用或权限异常

前端根据这些状态决定：

- 卡片中心区显示正常主数字还是异常提示
- 是否显示“兼容模式”标识
- 端口管理按钮是否继续可用
- 错误文案如何展示

## 配置页设计

华为云配置区域按“实例优先”重排字段顺序。

推荐顺序：

- `AccessKey ID`
- `AccessKey Secret`
- `Region ID`
- `Project ID`
- `Instance ID`
- `兼容安全组 ID（可选）`
- `备注`
- `账号级 SOCKS5 代理`

文案调整：

- `Security Group ID` 改名为 `兼容安全组 ID（可选）`
- 新增说明：默认会按实例自动发现绑定安全组，兼容安全组仅在发现失败时回退使用
- 华为云 `Region ID` 提供常见地域候选，不再完全依赖手输

校验规则：

- 华为云账号必须有 `AK/SK/Region ID/Project ID/Instance ID`
- `兼容安全组 ID` 可以为空
- 若填了兼容 SG，只做基础格式检查，不作为默认管理对象

旧账号提示：

- 若是华为云且缺少 `instance_id`，在配置卡片内显示“待升级”
- 允许用户查看旧配置
- 一旦重新保存，必须补齐 `Instance ID`

## 端口管理弹窗设计

弹窗继续使用统一“端口管理”入口，但上下文从“某个手填 SG”切换到“当前实例的安全组集合”。

弹窗头部展示：

- 账号
- 实例名称 / 实例 ID
- Region
- 公网 IP
- 当前发现模式

安全组选择逻辑：

- 默认列出实例当前绑定的全部安全组
- 若处于兼容模式，只列出回退 SG
- 若实例发现成功且绑定多个安全组，默认选第一个，同时允许切换其他安全组

规则列表保持现有结构，但要明确显示：

- 当前规则属于哪个安全组
- 当前是否处于兼容模式

若为兼容模式，弹窗顶部显示提示条：

- 当前未使用实例自动发现结果
- 当前操作对象来自兼容安全组 ID

## 运行时流程

### 主流程

1. 校验华为云账号是否具备 `AK/SK/Region ID/Project ID/Instance ID`
2. 调用 ECS 接口查询实例
3. 从实例结果提取实例名、公网 IP、状态和绑定安全组
4. 调用 VPC 接口加载这些安全组详情和入站规则
5. 组装首页卡片和端口管理弹窗所需数据

### 回退流程

当主流程失败时：

- 若存在兼容 `security_group_id`，进入 `security_group_fallback`
- 若不存在兼容 SG，返回明确错误状态

### 异常展示

首页卡片和弹窗都要展示用户可读的摘要错误，不只写日志。

前端可展示的摘要示例：

- `配置待补全：华为云账号需要 Project ID 和 Instance ID`
- `实例发现失败：无权限读取 ECS 实例信息`
- `实例发现失败，已回退到兼容安全组模式`

详细原始异常继续进入系统日志。

## 兼容迁移策略

旧华为云账号不能因为这一轮优化直接不可用。

迁移规则：

- 已有旧账号继续可读取
- 若旧账号只有 `security_group_id` 没有 `instance_id`，卡片显示为兼容模式或待升级
- 端口管理仍允许继续基于兼容 SG 工作
- 用户一旦编辑并保存该华为云账号，必须补齐 `instance_id`
- 新建华为云账号默认按新模型创建

## 代码落点

本轮主要改动文件：

- `providers/HuaweiCloudProvider.php`
- `providers/ProviderFactory.php`
- `AliyunTrafficCheck.php`
- `ConfigManager.php`
- `template.html`
- `MULTI_CLOUD_PLAN.md`

如果华为云 ECS 查询需要新增 SDK 引用或 endpoint 处理，则同步检查：

- `composer.json`
- `composer.lock`

## 测试范围

重点验证以下场景：

### 基础回归

- 阿里云账号首页卡片无回归
- 阿里云端口管理无回归
- 单账号 SOCKS5 代理对阿里云无回归

### 华为云配置

- 新建华为云账号缺少 `Instance ID` 时不能保存
- 旧华为云账号可读取
- 旧华为云账号重新保存时，未补齐 `Instance ID` 则失败

### 华为云自动发现

- 实例发现成功时，卡片显示实例语义和安全组数量
- 实例发现成功时，端口管理可列出全部绑定安全组
- 实例发现失败但兼容 SG 存在时，卡片显示 fallback 状态且端口管理可用
- 实例发现失败且无兼容 SG 时，卡片显示错误或待补全状态

### 代理与异常

- 华为云 ECS/VPC 请求在启用 SOCKS5 代理时仍可通过代理发出
- 华为云接口权限不足时，前端收到可读错误摘要

## 实施范围

本轮只实现以下内容：

- 华为云配置模型升级到实例优先
- 首页卡片与阿里云结构对齐
- 基于实例自动发现全部绑定安全组
- 保留兼容 `Security Group ID` 回退
- 端口管理弹窗切换为实例上下文

本轮明确不实现：

- 华为云真实流量口径
- 华为云账单能力
- 华为云实例开关机
- 多实例批量管理

## 验收标准

本轮完成后，华为云账号应满足以下体验标准：

- 用户新建华为云账号时，不需要再把手填安全组 ID 作为主路径
- 首页卡片可以一眼看出当前账号正在管理哪台实例或实例上下文
- 端口管理入口能够围绕实例关联安全组工作
- 即使自动发现失败，旧账号仍可在兼容模式下继续使用
- 阿里云现有功能不受影响
