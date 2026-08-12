# Lumio WHMCS Provisioning Module 1.1.7

[中文](#中文) | [English](#english)

## 中文

本模块供代理商在自己的 WHMCS 中销售、开通和管理 Lumio 服务。

### 支持环境

- WHMCS 9.0.6
- PHP 8.2
- PHP 扩展：cURL、JSON、mbstring
- WHMCS 9.0.6 官方支持的 MySQL 或 MariaDB
- HTTPS Lumio Integration API v1

### 安装

将 `lumio-whmcs-module-v1.1.7.zip` 上传到 WHMCS 安装根目录，然后执行：

```bash
unzip -o lumio-whmcs-module-v1.1.7.zip
```

模块会解压到 `modules/servers/lumio/`。

### API Key

为每套 WHMCS 创建独立的 Lumio API Key。

### WHMCS Server 配置

选择 `Lumio` 后只需填写：

| 字段 | 填写内容 |
| --- | --- |
| Hostname or IP Address | Lumio 用户端“账户设置 → API 对接”中显示的完整 `API Base URL` |
| API Key | 同一页面创建的完整 API Key |

运行 `Test Connection`，成功后保存 Server，再创建只包含这台 Lumio Server 的 Server Group。

### WHMCS 商品配置

1. 在商品的 `Module Settings` 中选择 `Lumio` 和对应 Server Group。
2. 在 `Lumio Product Mapping` 中选择商品、配置和附加产品。
3. 只启用需要的循环计费周期，关闭 `Prorata Billing`。
4. `Auto Setup` 建议设置为收到首笔付款后自动开通。
5. 确认立即终止政策后保存商品。商品页面只显示当前是否可以购买，不显示或同步 Lumio 的具体库存数量。

WHMCS cron 必须至少每五分钟运行一次。正式销售前请用已付款测试订单完成一次开通检查。

### 许可证

本模块以 [Apache License 2.0](LICENSE) 发布，代理商可以在遵守许可证的前提下修改和分发源码。

## English

This module lets resellers sell, provision, and manage Lumio services from their own WHMCS installation.

### Supported environment

- WHMCS 9.0.6
- PHP 8.2
- PHP extensions: cURL, JSON, and mbstring
- A MySQL or MariaDB version officially supported by WHMCS 9.0.6
- HTTPS Lumio Integration API v1

### Installation

Upload `lumio-whmcs-module-v1.1.7.zip` to the WHMCS installation root and run:

```bash
unzip -o lumio-whmcs-module-v1.1.7.zip
```

The module is extracted to `modules/servers/lumio/`.

### API Key

Create a separate Lumio API Key for each WHMCS installation.

### WHMCS Server configuration

After selecting `Lumio`, enter only these two values:

| Field | Value |
| --- | --- |
| Hostname or IP Address | The complete `API Base URL` shown under Account Settings → API Integration in Lumio |
| API Key | The complete API Key created on the same page |

Run `Test Connection`, save the Server after it succeeds, and create a Server Group containing only this Lumio Server.

### WHMCS product configuration

1. Select `Lumio` and the matching Server Group in the product's `Module Settings`.
2. Select the product, configuration, and add-ons in `Lumio Product Mapping`.
3. Enable only the required recurring billing cycles and disable `Prorata Billing`.
4. Set `Auto Setup` to provision after the first payment is received.
5. Confirm the immediate termination policy and save the product. The product page shows only whether the item is currently available; it does not display or synchronize Lumio inventory quantities.

The WHMCS cron must run at least every five minutes. Complete one paid test order before selling the product.

### License

This module is released under the [Apache License 2.0](LICENSE). Resellers may modify and redistribute the source code subject to the license terms.
