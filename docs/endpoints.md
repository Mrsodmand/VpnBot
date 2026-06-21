# Endpoints

Base URL:

```text
https://bot.ipsabet.app/api/wp-sync
```

## تست اتصال

`GET /ping`

## اتصال حساب

`POST /link/status`

Body:

```json
{"phone":"0912..."}
```

`POST /link/disconnect`

Body:

```json
{"phone":"0912..."}
```

## کیف پول

`POST /wallet/balance`

`POST /wallet/transactions`

`POST /wallet/credit`

```json
{"phone":"0912...","amount":250000,"source":"wordpress_wallet","ref_id":"WP-123"}
```

`POST /wallet/debit`

```json
{"phone":"0912...","amount":120000,"method":"wallet","ref_id":"WP-ORDER-100"}
```

## کاربران

`POST /users/list`

`POST /users/show`

`POST /users/update-status`

`POST /users/wallet-adjust`

```json
{"tel_id":"123456","direction":"credit","amount":100000,"note":"شارژ دستی"}
```

## سفارش ها

`POST /orders/list`

`POST /orders/show`

`POST /orders/import-site`

`POST /orders/update-bot`

`POST /orders/renew-bot`

## پرداخت ها / رسیدها

`POST /payments/list`

`POST /payments/show`

`POST /payments/approve`

`POST /payments/reject`

## گزارش ها

`POST /admin/stats`

```json
{"start":"2026-06-01","end":"2026-06-30","group":"day"}
```

`POST /admin/list`

```json
{"type":"orders","page":1,"search":""}
```

Type های پشتیبانی شده:

```text
orders, payments, users, panels, plans, services, countries, inbounds, carts, extra_bandwidths, links, site_orders, settings
```

## کاتالوگ

`POST /catalog/{type}`

`POST /catalog/{type}/{id}`

Type ها:

```text
services, plans, countries, panels, inbounds, extra_bandwidths, carts, settings, links, site_orders
```
