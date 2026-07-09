# BTCPay Server WHMCS Plugin — Greenfield API (`btcpaygreenfield`)

A **WHMCS payment gateway** for [BTCPay Server](https://btcpayserver.org) using the modern **Greenfield API**. Accept **Bitcoin and altcoin** payments on your own self-hosted BTCPay instance with webhook signature verification, store-scoped API calls, and a **fail-closed** payment callback.

**Developed by [TheOnionHost](https://theonionhost.com)** — hosting and billing integrations for WHMCS and BTCPay Server.

> **This repository contains the Greenfield module only** (`btcpaygreenfield`). It does not include the old legacy `btcpay` plugin files. If you still run the legacy gateway in WHMCS, keep that separately until you migrate.

## Why we built this (legacy problems)

The official WHMCS plugin (and forks based on v3.x) relied on BTCPay’s **deprecated Legacy BitPay-compatible API**. In production that caused real security and reliability issues:

| Problem (legacy) | Risk |
|------------------|------|
| **Legacy API key** + Basic-auth `POST /invoices` | Deprecated; many BTCPay versions push you toward Greenfield tokens |
| **`bp_lib.php` / `bpVerifyNotification()`** | Old IPN model, not store-level webhooks |
| **`crypt()` posData hashing** | Weak, outdated verification pattern |
| **Per-invoice `notificationURL` (IPN)** | No central webhook secret; harder to rotate and audit |
| **Callback trusted POST body** | Status/amount from webhook could be abused without full API re-fetch |
| **`$amount = ''` on `addInvoicePayment()`** | WHMCS auto-filled balance without validating paid amount |
| **No `BTCPay-Sig` HMAC** | Forged callbacks possible if other checks were weak |
| **No gateway-binding check** | Risk of crediting invoices assigned to another payment method |
| **No IDOR check on create invoice** | Client could potentially trigger payment for another user’s invoice ID |
| **No store-scoped API** | No `storeId`; weaker multi-store binding |
| **No payment-methods reconciliation** | Under/overpayment not validated against BTCPay API |

This plugin was written to fix those gaps using the **Greenfield API** and BTCPay **store webhooks**.

## Legacy API vs Greenfield — what’s different

| Area | Legacy (`btcpay` v3.x) | Greenfield (`btcpaygreenfield` v1) |
|------|------------------------|-------------------------------------|
| **Auth** | Legacy API key, Basic `Authorization` | Greenfield token: `Authorization: token <key>` |
| **Create invoice** | `POST /invoices` (BitPay shape) | `POST /api/v1/stores/{storeId}/invoices` |
| **Get invoice** | Legacy invoice endpoint | `GET /api/v1/stores/{storeId}/invoices/{id}` |
| **Notifications** | Per-invoice IPN URL | Store webhook + signing secret |
| **Callback verify** | `bpVerifyNotification`, posData hash | `BTCPay-Sig` HMAC + API re-fetch |
| **Trust model** | Body fields used for status flow | Only `invoiceId` from body; status/amount from API |
| **Payment amount** | Often empty string (auto-fill) | Computed and validated before credit |
| **WHMCS credit gate** | `paid` / `confirmed` / `complete` | API status **`Settled`** only |
| **Config** | Legacy API key, URL, speed | + **Store ID**, **Webhook secret**, tolerance, manual-mark toggle |
| **Module name** | `btcpay` | `btcpaygreenfield` (separate gateway in WHMCS) |
| **Callback URL** | `callback/btcpay.php` | `callback/btcpaygreenfield.php` |

## Features (Greenfield)

| Feature | Description |
|---------|-------------|
| Invoice creation | `POST /api/v1/stores/{storeId}/invoices` with `metadata.orderId` |
| Webhook callback | `modules/gateways/callback/btcpaygreenfield.php` |
| Security | HMAC verify, store-scoped GET, gateway binding, idempotency |
| IDOR protection | Logged-in client must own the WHMCS invoice |
| Manual mark (opt-in) | Test mode: credit when BTCPay marks `additionalStatus: Marked` |
| Tor support | Optional `.onion` BTCPay URL |

## Requirements

- [WHMCS](https://www.whmcs.com/) 7.x+
- [BTCPay Server](https://btcpayserver.org) with Greenfield API
- PHP with **cURL** and TLS

## Download

**Latest release:** [GitHub Releases](https://github.com/TheOnionHost/whmcs-btcpay-greenfield/releases/latest)

| Version | Download |
|---------|----------|
| v1.0.0 | [whmcs-btcpay-greenfield-v1.0.0.zip](https://github.com/TheOnionHost/whmcs-btcpay-greenfield/releases/download/v1.0.0/whmcs-btcpay-greenfield-v1.0.0.zip) |

Extract the zip into your WHMCS root directory (it contains the `modules/` folder).

## Installation

1. Download the latest release zip (above) **or** copy `modules/gateways/btcpaygreenfield.php`, `modules/gateways/btcpaygreenfield/`, and `modules/gateways/callback/btcpaygreenfield.php` into your WHMCS `modules/gateways/` tree.
2. WHMCS: **Setup → Payments → Payment Gateways** → activate **BTCPay Server Greenfield (v1)**.
3. Configure BTCPay (below).
4. Point products/invoices to **`btcpaygreenfield`**.

### File layout (this repo)

```
modules/gateways/btcpaygreenfield.php
modules/gateways/btcpaygreenfield/
modules/gateways/callback/btcpaygreenfield.php
```

## BTCPay Server setup

### API key

**Store → Settings → Access Tokens**:

- `btcpay.store.canviewinvoices`
- `btcpay.store.cancreateinvoice`

### Webhook

**Store → Settings → Webhooks**:

| Setting | Value |
|---------|-------|
| Payload URL | `https://YOUR-DOMAIN/modules/gateways/callback/btcpaygreenfield.php` |
| Events | `InvoiceSettled` (minimum) |
| Signing secret | WHMCS **Webhook Signing Secret** |

### WHMCS settings

| Field | Notes |
|-------|--------|
| Greenfield API Key | Access token |
| BTCPay Server URL | e.g. `https://btcpay.example.com` |
| Store ID | Your store ID |
| Webhook Signing Secret | From webhook |
| Payment Tolerance (%) | See [Known issues](#known-issues) |
| Allow Manually Marked Invoices | **Off in production** |

Module details: [modules/gateways/btcpaygreenfield/README.md](modules/gateways/btcpaygreenfield/README.md)

## Security overview

1. **BTCPay-Sig** HMAC on raw webhook body  
2. Only **`invoiceId`** read from webhook JSON  
3. Invoice + payment methods **re-fetched** from BTCPay API  
4. Credit only on API **`Settled`** (optional manual-mark branch if enabled)  
5. WHMCS **`paymentmethod`** must be `btcpaygreenfield`  
6. **`checkCbTransID()`** blocks double credit on redelivery  

## Migrating from legacy `btcpay`

1. Install this Greenfield module (this repo).  
2. Create a **new webhook** URL (do not reuse legacy IPN).  
3. Add Greenfield API key + Store ID + webhook secret in WHMCS.  
4. Test on new invoices with payment method **`btcpaygreenfield`**.  
5. When satisfied, switch products from legacy `btcpay` and disable the old gateway.  
6. Historical payments in `tblaccounts` stay as-is; only new flow uses Greenfield.

## Known issues

- **`Payment Tolerance (%)`** — not working as expected in all cases; fix planned in a future patch.

## About TheOnionHost

Built and maintained by **[TheOnionHost](https://theonionhost.com)** for WHMCS + BTCPay Server merchants who need a secure Greenfield integration after outgrowing the legacy API plugin.

[https://theonionhost.com](https://theonionhost.com)

## License

MIT. Greenfield module v1.0.0.

## References

- [BTCPay Server docs](https://docs.btcpayserver.org/)
- [Greenfield API v1](https://docs.btcpayserver.org/API/Greenfield/v1/)
