# Changelog / 更新记录

## 1.1.7 - 2026-08-12

### 中文

- 简化 WHMCS 9.0.6 的 Server 配置，可直接粘贴 Lumio 显示的完整 API Base URL。
- 改进异步开通、暂停、恢复和终止时的状态显示与失败处理。
- 改进客户区连接信息、API Key 保护和日志脱敏。
- 商品目录只显示是否可购买，不再显示或同步具体库存数量；同时改进重复请求保护。
- 异步生命周期任务固定每五分钟查询原操作，续费响应未知时由 cron 使用原幂等请求自动收口。
- 正式安装包包含 Apache License 2.0 许可证。

### English

- Simplified WHMCS 9.0.6 Server configuration so the complete Lumio API Base URL can be pasted directly.
- Improved status handling for asynchronous provisioning, suspension, resume, and termination.
- Improved Client Area connection details, API Key protection, and log redaction.
- Changed catalog mapping to show only availability without displaying or synchronizing inventory quantities, and improved duplicate-request protection.
- Polls the original asynchronous lifecycle operation every five minutes and safely reconciles unknown renewal responses through cron with the original idempotency data.
- Included the Apache License 2.0 text in the official installation package.
