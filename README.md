# Lumio WHMCS Provisioning Module

[简体中文](README.md) | [English](README.en.md)

Lumio 官方 WHMCS 自动开通模块，供代理商在自己的 WHMCS 中销售、开通和管理 Lumio 服务。

## 下载与安装

从 [v1.1.7 Release](https://github.com/lumiosh/lumio-whmcs-module/releases/tag/v1.1.7) 下载 `lumio-whmcs-module-v1.1.7.zip`，上传到 WHMCS 安装根目录，然后执行：

```bash
unzip -o lumio-whmcs-module-v1.1.7.zip
```

解压后模块位于 `modules/servers/lumio/`。

## 配置步骤

1. 在 Lumio 创建 API Key。
2. 在 WHMCS 后台添加 Lumio Server，填写 Lumio API 对接页显示的完整 `API Base URL` 和 API Key，然后测试连接。
3. 为 WHMCS 商品选择 Lumio 模块、Server Group 和对应的 Lumio 商品。
4. 确认 WHMCS cron 至少每五分钟运行一次。
5. 使用已付款测试订单检查自动开通结果。

完整步骤见[中文使用教程](docs/QUICKSTART.zh-CN.md)。

## 支持环境

- WHMCS 9.0.6
- PHP 8.2
- PHP 扩展：cURL、JSON、mbstring
- WHMCS 9.0.6 官方支持的 MySQL 或 MariaDB
- HTTPS Lumio Integration API v1

## 文档

- [中文使用教程](docs/QUICKSTART.zh-CN.md) / [English Quick Start](docs/QUICKSTART.en.md)
- [中文排错指南](docs/TROUBLESHOOTING.zh-CN.md) / [English Troubleshooting](docs/TROUBLESHOOTING.en.md)
- [模块说明](modules/servers/lumio/README.md)
- [v1.1.7 更新说明](modules/servers/lumio/CHANGELOG.md)

## 许可证

[Apache License 2.0](LICENSE)
