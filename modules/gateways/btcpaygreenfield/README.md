# BTCPay Server WHMCS Gateway — Greenfield (v1.0.0)

Parallel Greenfield API gateway module (`btcpaygreenfield`) for [BTCPay Server](https://btcpayserver.org). Runs **alongside** the legacy `btcpay` module so you can test without affecting production payments.

Developed by **[TheOnionHost](https://theonionhost.com)**.

## Module layout

| Component | Path |
|-----------|------|
| Gateway | `modules/gateways/btcpaygreenfield.php` |
| Support files | `modules/gateways/btcpaygreenfield/` |
| Webhook callback | `modules/gateways/callback/btcpaygreenfield.php` |
| WHMCS paymentmethod | `btcpaygreenfield` |

Legacy `btcpay` (v3.2.2) is unchanged and can remain active in parallel.

## Requirements

- BTCPay Server with Greenfield API
- PHP with cURL and TLS
- WHMCS 7.x+

## BTCPay setup

### 1. Greenfield API key

**Store → Settings → Access Tokens** — create a key with:

| Scope | Purpose |
|-------|---------|
| `btcpay.store.canviewinvoices` | Webhook callback: fetch invoice + payment methods |
| `btcpay.store.cancreateinvoice` | Create invoices on Pay Now |

### 2. Store ID

From your BTCPay store settings URL or `GET /api/v1/stores`.

### 3. Webhook (separate from legacy btcpay)

**Store → Settings → Webhooks**:

| Setting | Value |
|---------|-------|
| Payload URL | `https://your-whmcs-domain.com/modules/gateways/callback/btcpaygreenfield.php` |
| Events | `InvoiceSettled` minimum (or Everything) |
| Signing secret | Copy into WHMCS **Webhook Signing Secret** |

## WHMCS configuration

Activate **BTCPay Server Greenfield (v1)** separately from legacy BTCPay.

| Field | Description |
|-------|-------------|
| Greenfield API Key | API token |
| BTCPay Server URL | e.g. `https://btcpay.example.com` |
| Store ID | BTCPay store ID |
| Webhook Signing Secret | From webhook setup |
| Transaction Speed | low / medium / high |
| Payment Tolerance (%) | Underpayment margin (default 1) |
| Allow Manually Marked Invoices | See below |
| Redirect URL | Optional post-payment URL |
| Tor URL | Optional `.onion` support |

Assign test invoices/products to **btcpaygreenfield** only. Production invoices on legacy `btcpay` are unaffected.

## Known issue

- `Payment Tolerance (%)` is currently not working as expected in all cases.
- This will be fixed in the next patch update.

## Allow manually marked invoices

When **disabled** (default): invoices marked Settled by a BTCPay admin without payment are rejected.

When **enabled**: if BTCPay API reports `status: Settled` and `additionalStatus: Marked`, WHMCS credits the **full invoice total**. Useful for staging/testing or if you intentionally use BTCPay admin mark-as-settled.

This does **not** weaken webhook security — it only applies when BTCPay API confirms the manual mark. Enable only when you trust who can mark invoices settled in BTCPay.

## Security

The callback is fail-closed:

1. Valid `BTCPay-Sig` HMAC required
2. Only `invoiceId` read from webhook body (status/amount ignored)
3. Invoice re-fetched from BTCPay API
4. Underpayment rejected (unless manual-mark option applies)
5. WHMCS invoice `paymentmethod` must be `btcpaygreenfield`
6. `checkCbTransID()` prevents double-credit

A forged POST without the webhook secret cannot credit invoices.

## Migration path

1. Deploy `btcpaygreenfield` alongside legacy `btcpay`
2. Test with a separate webhook URL and test invoices
3. When ready, switch products to `btcpaygreenfield` and retire legacy `btcpay`

## About TheOnionHost

Plugin author and maintainer: **[TheOnionHost](https://theonionhost.com)** — WHMCS hosting, billing, and BTCPay Server integrations.

## Support

- [TheOnionHost](https://theonionhost.com)
- [BTCPay docs](https://docs.btcpayserver.org/)
- [Greenfield API](https://docs.btcpayserver.org/API/Greenfield/v1/)
