# CDT-Monitor 🌩️

> **多云 CDT 流量监控与自动化熔断解决方案（阿里云 / 华为云）**
>
> 在同一个面板内集中监控多家云厂商账号的实例流量、自动按阈值熔断、保活抢占式实例，避免免费额度超支与意外释放。

## 📖 项目概述 (Introduction)

**CDT-Monitor** 是一款轻量级多云流量监控与管理系统，基于 **PHP** + **SQLite**，无外部数据库依赖，几分钟内即可完成部署。

核心场景：

* **流量熔断**：阿里云 CDT 免费额度超支前自动停机，防止意外扣费
* **多账号统一管理**：阿里云、华为云账号在同一面板查看流量、状态、计费
* **保活策略**：抢占式实例被云平台异常释放时自动拉起
* **可视化**：近 24 小时与近 30 天流量曲线、阈值预警

适合个人开发者与中小团队控制多云成本。

## 🌐 支持的云厂商 (Supported Providers)

| 厂商 | 流量监控 | 实例开关 | 安全组管理 | 计费查询 | 备注 |
|---|---|---|---|---|---|
| **阿里云 (Aliyun)** | ✅ | ✅ | ✅ | ✅ | 通过 CDT 流量包 API |
| **华为云 (HuaweiCloud)** | ✅ | ✅ | ✅ | ⚠️ 部分 | 实例优先 + 安全组自动发现 |
| 腾讯云 (Tencent) | 🛠️ 规划中 | | | | |
| AWS | 🛠️ 规划中 | | | | |
| GCP | 🛠️ 规划中 | | | | |

底层使用 `providers/CloudProviderInterface.php` 抽象，新增厂商参考 `AliyunProvider.php`。

## 📸 界面预览 (Interface Preview)

| 登陆界面 | 初始化 |
| --- | --- |
| ![](https://assets.qninq.cn/qning/tzONAKQq.webp) | ![](https://assets.qninq.cn/qning/xDFaQTTX.webp) |

| 后台页面 | 后台页面 |
| --- | --- |
| ![](https://assets.qninq.cn/qning/WQG9I8qC.webp) | ![](https://assets.qninq.cn/qning/ipOStKeK.webp) |

| 图表展示 | 邮件展示 |
| --- | --- |
| ![](https://assets.qninq.cn/qning/a5zqmuJQ.webp) | ![](https://assets.qninq.cn/qning/6KEjK7bw.webp) |

## ✨ 核心功能 (Core Features)

* **多云架构**：基于 provider 抽象层支持多家云厂商，账号按厂商隔离能力与字段
* **便捷部署**：SQLite 单文件存储，无需 MySQL / Redis 等外部依赖
* **多账户聚合监控**：统一面板查看所有云厂商账号的流量、实例状态、计费
* **流量熔断机制**：
  * **阈值控制**：自定义流量使用阈值（如 95%）
  * **自动停机**：超阈值自动触发，支持 **普通停机** 与 **节省停机（停止计费）**
  * **告警通知**：SMTP 邮件、Telegram、自定义 Webhook
* **实例保活**：抢占式实例非预期释放时自动拉起，30 分钟冷却期防震荡
* **定时任务**：按实例配置每日自动开关机计划
* **安全组管理**：可视化端口规则增删
* **华为云实例发现**：账号只填实例 ID，自动发现关联安全组（兼容传统 SG-only 配置）
* **现代化 UI**：Tailwind CSS + Vue 3，响应式移动端 / 桌面端
* **本地加密配置**：敏感字段加密存储

## 🛠️ 环境要求 (System Requirements)

* **PHP** >= 8.0（推荐 8.2）
* **Composer**
* **PHP 扩展**：`pdo_sqlite`、`curl`、`json`、`bcmath`、`simplexml`、`mbstring`
* **Web 服务器**：Nginx / Apache / OpenLiteSpeed

> Docker 部署无需手动安装上述依赖。

## 🚀 安装指南 (Installation Guide)

### 1. 获取源代码

```bash
git clone https://github.com/xmm2022/CDT-Monitor.git
cd CDT-Monitor
```

### 2. 安装依赖

```bash
composer install --no-dev --optimize-autoloader
```

### 3. 配置目录权限

确保 Web 服务器用户对项目根目录及 `data/` 目录有 **写入权限**。

```bash
# 示例：Web 根目录为 /www/wwwroot/yoursite.com
chown -R www:www /www/wwwroot/yoursite.com
chmod -R 755 /www/wwwroot/yoursite.com
```

> **🛡️ Nginx 安全配置（重要）**
>
> 防止数据库文件被下载，请在 Nginx 配置（server 段）添加：
>
> ```nginx
> location ^~ /data/ {
>     deny all;
>     return 403;
> }
> ```

### 4. 系统初始化

通过浏览器访问站点（如 `https://monitor.yourdomain.com`），首次访问进入 **初始化向导**，按提示完成管理员密码与账号配置。

## 🐳 Docker 部署 (Docker Deployment)

无需配置 PHP 环境，Docker 镜像内置定时任务（cron），无需额外 Crontab。

### 方式一：Docker Compose（推荐）

`docker-compose.yml`：

```yaml
services:
  cdt-monitor:
    image: naxbr/cdt-monitor:latest
    container_name: cdt-monitor
    restart: always
    ports:
      - "43210:80"
    volumes:
      - ./data:/var/www/html/data
    environment:
      - TZ=Asia/Shanghai
```

启动：

```bash
docker-compose up -d
```

### 方式二：Docker CLI

```bash
docker run -d \
    --name cdt-monitor \
    --restart always \
    -p 43210:80 \
    -v $(pwd)/data:/var/www/html/data \
    -e TZ=Asia/Shanghai \
    naxbr/cdt-monitor:latest
```

部署完成后访问 `http://服务器IP:43210` 开始使用。

**镜像标签：**
- `naxbr/cdt-monitor:latest` — 跟随 main 分支
- `naxbr/cdt-monitor:v1.7.x` — 版本化稳定 tag
- `naxbr/cdt-monitor:arm` — arm64 架构

## ⚙️ 自动化任务配置 (Automation Setup)

> **Docker 部署可跳过本节**（容器已内置）。

`monitor.php` 需要每分钟执行一次以驱动流量监控与保活逻辑。

### 方式一：Crontab（推荐）

```cron
* * * * * /usr/bin/php /path/to/your/project/monitor.php > /dev/null 2>&1
```

### 方式二：URL 触发（Web Cron）

虚拟主机或第三方监控服务（如 UptimeRobot、宝塔「访问 URL」计划任务）可用此方式：

```
https://您的域名/monitor.php?key=您的管理员密码
```

> 必须携带 `key` 参数（即管理员密码），否则返回 403。

`monitor.php` 内置 API 缓存，高频触发不会导致额度超用。

## 💡 功能详解 (Advanced Documentation)

### 停机模式

| 模式 | 计费 | IP | 用途 |
|---|---|---|---|
| **普通停机 (KeepCharging)** | 持续 | 保留 | 快速启停场景 |
| **节省停机 (StopCharging)** | 停止 | 固定 IP 可能变（EIP 不变）| 长时间停机省钱 |

### 抢占式实例保活机制

* 系统实时监控「计划运行时间段」内的实例状态
* 检测到非预期关机（如被云平台释放）即尝试自动重启
* 触发后冷却 30 分钟，防止状态频繁震荡

### 华为云账号配置说明

填写实例 ID 后，安全组**自动从实例发现**，不需要单独填 SG ID。若 SDK 调用失败会回退到旧的「直接填 SG ID」模式。需要的字段：

- AK / SK
- Region（如 `ap-southeast-3`）
- Project ID（IAM 项目 ID）
- Instance ID
- （可选）Security Group ID — 作为兜底

## 🤝 参与贡献 (Contributing)

欢迎提交 Issue 或 Pull Request。新增云厂商参考 `providers/AliyunProvider.php` 实现 `CloudProviderInterface` 即可。

## 📄 开源协议 (License)

[MIT License](https://mit-license.org/)

## 🙏 致谢 (Credits)

本项目基于 [wang4386/CDT-Monitor](https://github.com/wang4386/CDT-Monitor)（原作者 [青柠·倾城于你](https://qninq.cn/)）扩展多云支持。

<p align="center">Forked & extended by <a href="https://github.com/xmm2022">xmm2022</a></p>
