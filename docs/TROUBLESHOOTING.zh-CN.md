# Lumio WHMCS v1.1.7 排错指南

[简体中文](TROUBLESHOOTING.zh-CN.md) | [English](TROUBLESHOOTING.en.md)

排错前先记录模块版本、操作、稳定错误码和 Request-Id。不要把完整 API Key、服务密码、客户资料、数据库内容或完整日志发布到 GitHub Issue。

## Test Connection 失败

| 现象或错误 | 处理方法 |
| --- | --- |
| `AUTH_INVALID` / `KEY_REVOKED` | 在 Lumio 创建或轮换 Key，然后更新 WHMCS Server 的 `API Key` 字段。 |
| `SCOPE_DENIED` | 在 Lumio 的 API 对接页面重新创建用于 WHMCS 的 API Key。 |
| 连接失败或 HTTPS 错误 | 重新复制 Lumio API 对接页面显示的完整 `API Base URL`，并确认地址使用 HTTPS。 |
| 账号不允许 API v1 自动购买 | 检查 Lumio 账号状态、API 对接是否启用以及购买权限。 |

修改后再次运行 `Test Connection`，不要跳过失败强行保存为可用配置。

## 商品映射没有出现

1. 确认 Module Name 为 `Lumio`。
2. 确认已经选择 Server Group，且组内恰好有一台已启用的 Lumio Server。
3. 确认该 Server 的 `Test Connection` 成功。
4. 如果升级后才覆盖新版 `hooks.php`，在商品的 Module Settings 中保存一次。
5. 查看浏览器请求和 Module Log 中是否有目录读取错误。

目录临时不可用时，模块会保留原始字段和已有映射，不会因为一次失败自动清空配置。

## 已付款订单仍然 Pending

| 错误码或状态 | 实际含义和下一步 |
| --- | --- |
| `OUT_OF_STOCK` | Lumio 当前无可售库存。补货后由管理员明确重试。 |
| `WALLET_INSUFFICIENT` | Lumio 钱包余额不足。充值后明确重试。 |
| `PRICE_CHANGED` | Lumio 成本超过 WHMCS 商品保存的成本上限。重新打开商品映射、核价、保存后重试。 |
| `PRODUCT_NOT_FOUND` | 商品已下架、不可购买或当前账号不可见。重新选择商品映射。 |
| `INVALID_SELECTION` | 周期、固定配置或附加产品组合无效。重新保存映射。 |
| `CREDENTIALS_NOT_READY` | 服务已进入开通过程，但凭据尚未准备好。保持 cron 每五分钟运行并等待原任务收口。 |
| `TRANSPORT_ERROR` | 网络结果未知。模块会保留原业务编号和幂等键；不要新建另一笔购买。 |

确定性错误需要管理员先处理原因，再明确重试。模块不会让 cron 每五分钟重新购买。

## 暂停、恢复或终止没有完成

- 暂停请求被安全接收后，WHMCS 可以先显示成功，cron 会继续确认最终状态。
- 如果 Lumio 最终暂停失败，模块会把 WHMCS 服务恢复为 Active。
- 恢复和终止只有在 Lumio 达到最终状态后才完成。
- `OTHER_HOLDS_REMAIN` 表示服务仍有其他限制，需先处理欠费、管理员或其他系统限制。
- 立即终止会释放资源并放弃剩余付费周期，Lumio 不自动退款。

## 检查位置

- `Utilities（实用工具） → Module Queue（模块队列）`
- `Configuration（配置） → System Logs（系统日志） → Module Log（模块日志）`
- WHMCS Activity Log（活动日志）
- `Utilities（实用工具） → Automation Status（自动化状态）`

提供支持信息时只需：WHMCS/PHP/模块版本、操作、脱敏错误全文、稳定错误码、Request-Id 和已经尝试过的步骤。

返回[中文使用教程](QUICKSTART.zh-CN.md)。
