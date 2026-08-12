# Lumio WHMCS Provisioning Module

[简体中文](README.md) | [English](README.en.md)

The official Lumio provisioning module lets resellers sell, provision, and manage Lumio services from their own WHMCS installation.

## Download and install

Download `lumio-whmcs-module-v1.1.7.zip` from the [v1.1.7 Release](https://github.com/lumiosh/lumio-whmcs-module/releases/tag/v1.1.7), upload it to the WHMCS installation root, and run:

```bash
unzip -o lumio-whmcs-module-v1.1.7.zip
```

The module is extracted to `modules/servers/lumio/`.

## Configuration

1. Create a Lumio API Key.
2. Add a Lumio Server in WHMCS, enter the complete `API Base URL` shown on the Lumio API Integration page and the API Key, and test the connection.
3. Select the Lumio module, Server Group, and Lumio product for the WHMCS product.
4. Make sure the WHMCS cron runs at least every five minutes.
5. Verify provisioning with a paid test order.

See the [English Quick Start](docs/QUICKSTART.en.md) for the complete setup.

## Supported environment

- WHMCS 9.0.6
- PHP 8.2
- PHP extensions: cURL, JSON, and mbstring
- A MySQL or MariaDB version officially supported by WHMCS 9.0.6
- HTTPS Lumio Integration API v1

## Documentation

- [中文使用教程](docs/QUICKSTART.zh-CN.md) / [English Quick Start](docs/QUICKSTART.en.md)
- [中文排错指南](docs/TROUBLESHOOTING.zh-CN.md) / [English Troubleshooting](docs/TROUBLESHOOTING.en.md)
- [Module Guide](modules/servers/lumio/README.md)
- [v1.1.7 Changelog](modules/servers/lumio/CHANGELOG.md)

## License

[Apache License 2.0](LICENSE)
