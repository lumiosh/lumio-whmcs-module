# Changelog / 更新记录

## 1.1.8 - 2026-11-18

### 中文

- 续费：Lumio 临时限流或短暂不可用时，已付款的续费账单会由 cron 自动补做；多张已付款续费账单按顺序逐张处理，不再互相卡住。
- 续费：升级前已付款但未被模块记录的历史账单不会被自动再次扣费；需要人工处理的续费不会被下一次自动付款重复触发。
- 暂停：设备暂停失败时会释放本次暂停并恢复 WHMCS 状态，网络中断时保留原请求继续收口；Lumio 侧已处于暂停时也会记录本次暂停，避免之后被误恢复。
- 开通：超过一小时仍未完成时停止自动查询，并在 Activity Log 写明服务编号和手动重试方法；手动重试沿用原请求，不会重复购买。
- 商品映射：映射没有改动时，不再阻止保存商品的其它设置（隐藏、描述、售价等）。
- 商品映射：未勾选 “Immediate Termination Policy” 时不再提示映射完成；改动映射后仍未勾选会阻止保存，避免客户付款后无法开通。
- 商品映射：每个计费周期显示商品已保存的 USD 售价，低于 Lumio 成本时标红提醒（只提醒，不阻止保存）。
- 客户区：服务器地址、主机名、用户名可一键复制，并新增可复制的 SSH 连接命令。

### English

- Renewals: paid renewal invoices are completed by cron after temporary Lumio rate limiting or unavailability, and several paid renewal invoices are processed in order instead of blocking each other.
- Renewals: historical paid invoices that the module never recorded are not charged again automatically after upgrading, and renewals that need attention are not retried by the next automatic payment.
- Suspension: a suspension the device fails to apply is released and the WHMCS status restored, keeping the original request across network loss; a suspension requested while Lumio has already suspended the service is now recorded so it is not resumed by mistake.
- Provisioning: polling stops after one hour without completion and the Activity Log records the service ID and how to retry manually; a manual retry reuses the original request and never purchases twice.
- Product mapping: an unchanged mapping no longer blocks saving other product settings such as Hidden, description, or pricing.
- Product mapping: the mapping is no longer reported as complete while “Immediate Termination Policy” is unticked, and an edited mapping cannot be saved until it is ticked, so paid orders are not left unprovisioned.
- Product mapping: each billing cycle shows the product's saved USD price and highlights cycles priced below the Lumio cost (a warning only; saving is still allowed).
- Client Area: the server address, hostname, and username can be copied with one click, and a copyable SSH command is shown.

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
