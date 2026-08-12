# Lumio WHMCS v1.1.7 Quick Start

[简体中文](QUICKSTART.zh-CN.md) | [English](QUICKSTART.en.md)

## 1. Install the module

Download `lumio-whmcs-module-v1.1.7.zip` from the [v1.1.7 Release](https://github.com/lumiosh/lumio-whmcs-module/releases/tag/v1.1.7), upload it to the WHMCS installation root, and run:

```bash
unzip -o lumio-whmcs-module-v1.1.7.zip
```

The module is extracted to `modules/servers/lumio/`.

## 2. Create a Lumio API Key

Open Account Settings → API Integration in Lumio and create a dedicated API Key for this WHMCS installation.

Enter the full API Key only on the WHMCS Server page. Never post it in an issue, chat, log, or screenshot.

## 3. Add the Lumio Server

In the WHMCS Admin Area, go to:

`Configuration → System Settings → Servers → Add New Server`

After selecting `Lumio`, enter only these two values:

| WHMCS field | Value |
| --- | --- |
| Hostname or IP Address | The complete `API Base URL` shown under Account Settings → API Integration in Lumio |
| API Key | The complete API Key created on the same page |

Run `Test Connection`. After it succeeds, save the Server and create a Server Group that contains only this Lumio Server.

## 4. Configure the WHMCS product

Go to:

`Configuration → System Settings → Products/Services`

Open the product's `Module Settings`:

1. Select `Lumio` for `Module Name` and select the Server Group created above.
2. In `Lumio Product Mapping`, select the Lumio product, configuration, and add-ons to sell.
3. Enable only the recurring billing cycles you need and disable `Prorata Billing`.
4. Set `Auto Setup` to provision after the first payment is received.
5. Confirm the immediate termination policy and save the product. The product page shows only whether the item is currently available; it does not display or synchronize Lumio inventory quantities.

![WHMCS Lumio product configuration](images/v1.1.7/product-mapping.png)

The reseller controls the WHMCS retail price.

## 5. Enable cron and test

Copy the system cron command shown in WHMCS `Automation Settings` and run it at least every five minutes.

Complete one paid order with a test client and product. Confirm that the service eventually becomes `Active`, the Client Area shows the connection details, and Module Queue has no unhandled errors.

See [English Troubleshooting](TROUBLESHOOTING.en.md) if the workflow does not complete.
