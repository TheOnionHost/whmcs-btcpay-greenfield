# BTCPay Server WHMCS Plugin — Greenfield API Gateway (`btcpaygreenfield`)

A **WHMCS payment gateway** for [BTCPay Server](https://btcpayserver.org) using the modern **Greenfield API**. Accept **Bitcoin and altcoin** payments on your own self-hosted BTCPay instance with webhook signature verification, store-scoped API calls, and a **parallel** module so you can test beside the legacy `btcpay` gateway without breaking production.

**Developed by [TheOnionHost](https://theonionhost.com)** — hosting and billing integrations for WHMCS and BTCPay Server.

## Why this plugin

- **Greenfield API** — replaces the deprecated Legacy BitPay-compatible API (`bp_lib.php`, Basic-auth `/invoices`).
- **Parallel gateway** — new module name `btcpaygreenfield`; legacy `btcpay` (v3.2.2) stays untouched for safe migration.
- **Fail-closed webhooks** — HMAC verification (`BTCPay-Sig`), API re-fetch, amount reconciliation, gateway binding, idempotency.
- **Production-tested** — real settled payments and webhook redelivery handling validated on live WHMCS + BTCPay.

## Features

| Feature | Description |
|---------|-------------|
| Invoice creation | `POST /api/v1/stores/{storeId}/invoices` with metadata `orderId` |
| Webhook callback | `modules/gateways/callback/btcpaygreenfield.php` |
| Security | Signature verify, store-scoped GET, no trust of webhook body status/amount |
| IDOR protection | Client must own WHMCS invoice before BTCPay invoice is created |
| Manual mark (opt-in) | Testing mode: credit when BTCPay admin marks invoice Settled (`additionalStatus: Marked`) |
| Tor support | Optional `.onion` BTCPay URL when WHMCS is accessed via Tor |

## Requirements

- [WHMCS](https://www.whmcs.com/) 7.x or later
- [BTCPay Server](https://btcpayserver.org) with Greenfield API
- PHP with **cURL** and TLS (HTTPS)

## Installation

1. Copy the `modules` folder into your WHMCS root (merge with existing `modules/gateways/`).
2. In WHMCS: **Setup → Payments → Payment Gateways** → activate **BTCPay Server Greenfield (v1)**.
3. Configure BTCPay (see below).
4. Assign test products/invoices to **`btcpaygreenfield`** only until you complete migration.

### File layout

```
modules/gateways/btcpaygreenfield.php          # Gateway config + Pay Now form
modules/gateways/btcpaygreenfield/             # Greenfield helpers + createinvoice
modules/gateways/callback/btcpaygreenfield.php # Webhook handler
```

Legacy files under `modules/gateways/btcpay/` remain for the original plugin.

## BTCPay Server setup

### API key (Greenfield)

**Store → Settings → Access Tokens** — scopes:

- `btcpay.store.canviewinvoices`
- `btcpay.store.cancreateinvoice`

### Webhook

**Store → Settings → Webhooks**:

| Setting | Value |
|---------|-------|
| Payload URL | `https://YOUR-WHMCS-DOMAIN/modules/gateways/callback/btcpaygreenfield.php` |
| Events | `InvoiceSettled` (minimum) |
| Signing secret | Paste into WHMCS **Webhook Signing Secret** |

Use a **separate webhook** from legacy `btcpay` IPN.

### WHMCS gateway settings

| Field | Notes |
|-------|--------|
| Greenfield API Key | Token from Access Tokens |
| BTCPay Server URL | e.g. `https://btcpay.example.com` |
| Store ID | From store settings or API |
| Webhook Signing Secret | From webhook creation |
| Transaction Speed | Maps to BTCPay `speedPolicy` |
| Payment Tolerance (%) | See [Known issues](#known-issues) |
| Allow Manually Marked Invoices | **Off in production**; testing only |

Detailed module docs: [modules/gateways/btcpaygreenfield/README.md](modules/gateways/btcpaygreenfield/README.md)

## Security overview

1. Raw webhook body verified with **HMAC** (`webhookSecret`).
2. Only **`invoiceId`** is read from the webhook JSON.
3. Invoice and payment data **re-fetched** from BTCPay API (store-scoped URLs).
4. Credits only when API reports **`Settled`** (with optional manual-mark branch if enabled).
5. WHMCS invoice **`paymentmethod`** must be `btcpaygreenfield`.
6. **`checkCbTransID()`** prevents double payment on webhook redelivery.

Forged POST requests without the webhook secret cannot credit invoices.

## Migration from legacy `btcpay`

1. Keep legacy `btcpay` active for existing invoices.
2. Deploy `btcpaygreenfield` and configure a new webhook URL.
3. Test with small invoices on `btcpaygreenfield`.
4. Switch products/payment methods when ready; historical `tblaccounts` rows stay valid.

## Known issues

- **`Payment Tolerance (%)`** — not working as expected in all cases; fix planned in a future patch. Until then, rely on BTCPay store checkout tolerance or full payment amounts.

## About TheOnionHost

This plugin was built and maintained by **[TheOnionHost](https://theonionhost.com)** for WHMCS merchants who run their own BTCPay Server and need a secure Greenfield integration without replacing legacy gateways overnight.

For hosting, WHMCS, and Bitcoin payment infrastructure: [https://theonionhost.com](https://theonionhost.com)

## License

MIT (see upstream BTCPay WHMCS plugin lineage). Greenfield module v1.0.0.

## References

- [BTCPay Server documentation](https://docs.btcpayserver.org/)
- [Greenfield API v1](https://docs.btcpayserver.org/API/Greenfield/v1/)
