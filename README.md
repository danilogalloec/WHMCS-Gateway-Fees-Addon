# WHMCS Gateway Fees Addon

This WHMCS addon allows you to add fees based on the payment gateway being used. It supports applying fees to invoices, updating fees when payment methods are changed, and handling changes made by both clients and administrators.

## You like this module? [Buy me a Coffee](https://buymeacoffee.com/nikba) ☕︎

> **Version 2.1.1** — Fully compatible with **WHMCS 9.x** and **PHP 8.1+**, with a dedicated admin management interface.

## Features

- Fixed and percentage-based fees — or **discounts** (negative values) — per gateway.
- **Minimum invoice threshold** and **maximum fee cap** per gateway.
- **Configurable percentage base** — item subtotal (excl. tax) or total incl. tax.
- **Exempt client groups** from fees.
- Dedicated admin management interface with overview stats and per-gateway enable/disable.
- One-click recalculation of fees across all unpaid invoices, or apply-on-save.
- Optional Activity Log entries and taxable-fee support.
- Automatically applies fees on invoice creation and payment-method changes.
- Fully translatable via `lang/`.

## Requirements

- WHMCS 8.x or 9.x
- PHP 8.1 or newer

## Installation

1. **Download the addon:**
   - Clone the repository or download the ZIP file and extract it.

   ```bash
   git clone https://github.com/Nikba-Creative-Studio/WHMCS-Gateway-Fees-Addon.git
   ```

2. **Upload the addon:**
   - Upload the `gateway_fees` folder to the `modules/addons/` directory of your WHMCS installation.

3. **Activate the addon:**
   - Log in to your WHMCS admin panel.
   - Go to **Setup** > **Addon Modules**.
   - Locate **Gateway Fees** and click the **Activate** button.

## Configuration

**Global options** live under **Setup → Addon Modules → Gateway Fees → Configure**:
- **Fee Label** — the text shown on the invoice line item and checkout page (e.g. "Gateway Fee").
- **Percentage Base** — `subtotal` (item subtotal, excl. tax) or `total` (subtotal + estimated tax).
- **Apply Tax to Fees** — mark the fee line item as taxable.
- **Exempt Client Groups** — comma-separated client group IDs that are never charged a fee.
- **Log to Activity Log** — write an Activity Log entry each time a fee is applied.
- **Show Fees on Checkout Page** — display an estimated fee on the checkout page (Twenty-One theme).

**Per-gateway fees** are managed from the addon page under **Addons → Gateway Fees**:
- **Fixed Fee / Percentage Fee** — use negative values to apply a **discount** instead.
- **Min. Amount** — only apply the fee when the invoice base is at or above this value.
- **Max. Fee** — cap the fee at this amount (0 = no cap).
- Each gateway can be individually enabled or disabled.

You can also use **Recalculate Unpaid Invoices** to re-apply the current rules to every open invoice at once.

## Usage

The addon automatically applies the configured fees based on the payment gateway used for the invoice, and updates them if the payment method is changed by the client or administrator.

## Upgrading from 1.x

Version 2.0 stores fees in a dedicated `mod_gateway_fees` table. When you upload the new files and let WHMCS run the upgrade, your existing `fee_1_*` / `fee_2_*` settings are migrated automatically and the old settings are cleaned up.

## Support

For support or questions, please open an issue on the GitHub repository.

## License

This addon is open-source and licensed under the MIT License.

## Version 2.1.1 - Changelog

- Fixed the invoice **total not refreshing** when fees are applied via Recalculate / apply-on-save (the fee line was written but the stored total stayed stale).

## Version 2.1 - Changelog

- **Discounts** (negative fees), **minimum threshold** and **maximum cap** per gateway.
- **Configurable percentage base** (subtotal or total incl. tax) and **client-group exemptions**.
- Admin **overview stats**, **apply-on-save**, optional **Activity Log**, and **i18n** via `lang/`.
- Fixed fees not applying to **admin-created invoices**; **partial-payment** guard; **checkout estimate** now mirrors server-side rules.

## Version 2.0 - Changelog

### WHMCS 9.x Compatibility & Major Rewrite

1. **WHMCS 9.x / PHP 8.1+ compatibility** — removed the fragile reimplementation of WHMCS' internal invoice/tax calculation and added null-safety throughout.
2. **New admin management interface** (`_output`) — manage per-gateway fees from a dedicated page instead of dozens of auto-generated config fields.
3. **Dedicated database table** (`mod_gateway_fees`) with automatic migration of legacy 1.x settings.
4. **Proper module lifecycle** — `_activate` returns a status array and creates the table; added `_deactivate` and `_upgrade`.
5. **Safer hooks** — all functions prefixed (`gatewayfees_*`), configurable fee label, optional taxing of the fee line.

## Version 1.2 - Changelog

### Updates and Improvements

1. **Currency Display Fix**:
   - The module now correctly displays fees in the default currency set in the WHMCS system. This fix ensures that all fees are shown in the appropriate currency without needing manual adjustments.

2. **Fee Display on Checkout Page for Twenty-One Template**:
   - Fees associated with payment methods configured via this module are now displayed on the checkout page when using the default Twenty-One template.
   - **Note for Developers**: If you are using a custom template, you might need to make adjustments to the `ShoppingCartCheckoutOutput` hook in the `hooks.php` file to ensure that fees are displayed correctly on the checkout page.
