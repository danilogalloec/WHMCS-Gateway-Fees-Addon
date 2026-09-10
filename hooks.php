<?php

/**
 * WHMCS Gateway Fees Addon — Hooks
 *
 * Applies the configured per-gateway fee to invoices and (optionally) shows an
 * estimate on the checkout page.
 *
 * Compatible with WHMCS 9.x / PHP 8.1+.
 *
 * @package    WHMCS\Addon\GatewayFees
 * @author     Nikba Creative Studio
 * @license    MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

if (!defined('GATEWAY_FEES_TABLE')) {
    define('GATEWAY_FEES_TABLE', 'mod_gateway_fees');
}

/**
 * Read an addon global setting from tbladdonmodules.
 *
 * @param string $setting
 * @param mixed  $default
 * @return mixed
 */
function gatewayfees_setting($setting, $default = null)
{
    $value = Capsule::table('tbladdonmodules')
        ->where('module', 'gateway_fees')
        ->where('setting', $setting)
        ->value('value');

    return ($value === null || $value === '') ? $default : $value;
}

/**
 * Fetch the fee rule for a gateway.
 *
 * @param string $gateway
 * @return object|null
 */
function gatewayfees_get_rule($gateway)
{
    if (empty($gateway) || !Capsule::schema()->hasTable(GATEWAY_FEES_TABLE)) {
        return null;
    }

    return Capsule::table(GATEWAY_FEES_TABLE)
        ->where('gateway', $gateway)
        ->where('enabled', 1)
        ->first();
}

/**
 * Resolve the friendly display name of a gateway (null-safe).
 *
 * @param string $gateway
 * @return string
 */
function gatewayfees_gateway_name($gateway)
{
    $name = Capsule::table('tblpaymentgateways')
        ->where('gateway', $gateway)
        ->where('setting', 'name')
        ->value('value');

    return $name ?: ucfirst((string) $gateway);
}

/**
 * Determine whether an invoice already has recorded payments/transactions.
 * We never alter such invoices to avoid corrupting a paid/partial balance.
 *
 * @param int $invoiceId
 * @return bool
 */
function gatewayfees_has_payments($invoiceId)
{
    return Capsule::table('tblaccounts')
        ->where('invoiceid', $invoiceId)
        ->exists();
}

/**
 * Check whether a client belongs to a fee-exempt group.
 *
 * @param int $userid
 * @return bool
 */
function gatewayfees_is_exempt($userid)
{
    $raw = trim((string) gatewayfees_setting('exempt_groups', ''));
    if ($raw === '') {
        return false;
    }

    $exempt = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
    if (!$exempt) {
        return false;
    }

    $groupId = Capsule::table('tblclients')->where('id', $userid)->value('groupid');

    return in_array((string) $groupId, $exempt, true);
}

/**
 * Compute the base amount a percentage fee is applied to.
 *
 * @param object $invoice
 * @return float
 */
function gatewayfees_base_amount($invoice)
{
    $items = Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoice->id)
        ->where('notes', '!=', 'gateway_fees')
        ->get();

    $subtotal      = 0.0;
    $taxedSubtotal = 0.0;
    foreach ($items as $item) {
        $subtotal += (float) $item->amount;
        if ((int) $item->taxed === 1) {
            $taxedSubtotal += (float) $item->amount;
        }
    }

    if (gatewayfees_setting('fee_base', 'subtotal') !== 'total') {
        return $subtotal;
    }

    // "Total incl. tax" mode: add a simple, non-compound tax estimate.
    $tax = 0.0;
    if ($taxedSubtotal > 0) {
        $tax += $taxedSubtotal * ((float) $invoice->taxrate / 100);
        $tax += $taxedSubtotal * ((float) $invoice->taxrate2 / 100);
    }

    return $subtotal + $tax;
}

/**
 * Calculate the signed fee amount for a rule against a base value.
 * Supports fixed + percentage, negative values (discounts), a minimum invoice
 * threshold and a maximum fee cap.
 *
 * @param object $rule
 * @param float  $base
 * @return float
 */
function gatewayfees_calculate($rule, $base)
{
    $minAmount = (float) ($rule->min_amount ?? 0);
    if ($minAmount > 0 && $base < $minAmount) {
        return 0.0;
    }

    $fee = (float) $rule->fee_fixed + ($base * (float) $rule->fee_percent / 100);

    $maxFee = (float) ($rule->max_fee ?? 0);
    if ($maxFee > 0 && abs($fee) > $maxFee) {
        $fee = ($fee < 0 ? -1 : 1) * $maxFee;
    }

    return round($fee, 2);
}

/**
 * Apply / refresh the gateway fee on a single invoice.
 *
 * @param array $vars
 * @return void
 */
function gatewayfees_update_invoice($vars)
{
    $invoiceId = $vars['invoiceid'] ?? null;
    if (!$invoiceId) {
        return;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return;
    }

    // Never touch invoices that already carry payments (paid or partial).
    if (gatewayfees_has_payments($invoiceId)) {
        return;
    }

    // Always remove the previous fee line first so we never stack fees.
    Capsule::table('tblinvoiceitems')
        ->where('invoiceid', $invoiceId)
        ->where('notes', 'gateway_fees')
        ->delete();

    // Exempt client groups get no fee at all.
    if (gatewayfees_is_exempt($invoice->userid)) {
        gatewayfees_recalc_total($invoiceId);
        return;
    }

    $rule = gatewayfees_get_rule($invoice->paymentmethod);
    if (!$rule) {
        gatewayfees_recalc_total($invoiceId);
        return;
    }

    $base      = gatewayfees_base_amount($invoice);
    $feeAmount = gatewayfees_calculate($rule, $base);

    if ($feeAmount != 0.0 && $base > 0) {
        $taxed       = gatewayfees_setting('tax_fees', 'off') === 'on' ? 1 : 0;
        $description  = gatewayfees_build_description($invoice->paymentmethod, $rule, $feeAmount);

        Capsule::table('tblinvoiceitems')->insert([
            'userid'        => $invoice->userid,
            'invoiceid'     => $invoiceId,
            'type'          => 'Fee',
            'notes'         => 'gateway_fees',
            'description'   => $description,
            'amount'        => $feeAmount,
            'taxed'         => $taxed,
            'duedate'       => date('Y-m-d'),
            'paymentmethod' => $invoice->paymentmethod,
        ]);

        if (gatewayfees_setting('log_activity', 'off') === 'on' && function_exists('logActivity')) {
            logActivity(
                'Gateway Fees: applied ' . number_format($feeAmount, 2)
                . ' to Invoice #' . $invoiceId . ' (' . $invoice->paymentmethod . ')',
                $invoice->userid
            );
        }
    }

    gatewayfees_recalc_total($invoiceId);
}

/**
 * Build the invoice line-item description (handles discounts).
 *
 * @param string $gateway
 * @param object $rule
 * @param float  $feeAmount
 * @return string
 */
function gatewayfees_build_description($gateway, $rule, $feeAmount)
{
    $label     = gatewayfees_setting('fee_label', 'Gateway Fee');
    $feeFixed   = (float) $rule->fee_fixed;
    $feePercent = (float) $rule->fee_percent;

    $parts = [];
    if ($feeFixed != 0.0) {
        $parts[] = number_format($feeFixed, 2);
    }
    if ($feePercent != 0.0) {
        $parts[] = rtrim(rtrim(number_format($feePercent, 4, '.', ''), '0'), '.') . '%';
    }
    $breakdown = $parts ? ' (' . implode(' + ', $parts) . ')' : '';

    if ($feeAmount < 0) {
        $label = 'Discount';
    }

    return gatewayfees_gateway_name($gateway) . ' ' . $label . $breakdown;
}

/**
 * Recalculate and persist the invoice total using WHMCS' own helper.
 *
 * updateInvoiceTotal() lives in includes/invoicefunctions.php, which is loaded
 * automatically in the billing/hook context but NOT when this runs from the
 * admin addon page (Recalculate). Load it on demand so totals are always
 * refreshed regardless of the calling context.
 *
 * @param int $invoiceId
 * @return void
 */
function gatewayfees_recalc_total($invoiceId)
{
    // Load WHMCS' own helper (best tax accuracy). It lives in
    // includes/invoicefunctions.php, loaded automatically in the billing/hook
    // context but NOT when this runs from the admin addon page (Recalculate).
    if (!function_exists('updateInvoiceTotal')) {
        $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 3);
        $path = $root . '/includes/invoicefunctions.php';
        if (is_file($path)) {
            require_once $path;
        }
    }

    if (function_exists('updateInvoiceTotal')) {
        updateInvoiceTotal($invoiceId);
    }

    // Safety net: in some contexts the helper is unavailable or a no-op, which
    // leaves the stored total stale after we change a line item. For invoices
    // with no tax the correct total is unambiguous (sum of items minus credit),
    // so force it when it does not match. Taxed invoices are left to WHMCS.
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return;
    }

    if ((float) $invoice->taxrate == 0.0 && (float) $invoice->taxrate2 == 0.0) {
        $subtotal = (float) Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->sum('amount');
        $total = round(max(0, $subtotal - (float) $invoice->credit), 2);

        if (round((float) $invoice->total, 2) !== $total) {
            Capsule::table('tblinvoices')->where('id', $invoiceId)->update([
                'subtotal' => round($subtotal, 2),
                'total'    => $total,
            ]);
        }
    }
}

/**
 * Re-apply fees to every unpaid invoice of a client (used when the client's
 * default payment method changes).
 *
 * @param int    $userid
 * @param string $newPaymentMethod
 * @return void
 */
function gatewayfees_update_client_invoices($userid, $newPaymentMethod)
{
    if (empty($userid)) {
        return;
    }

    $invoices = Capsule::table('tblinvoices')
        ->where('userid', $userid)
        ->where('status', 'Unpaid')
        ->pluck('id');

    foreach ($invoices as $invoiceId) {
        if (!empty($newPaymentMethod)) {
            Capsule::table('tblinvoices')
                ->where('id', $invoiceId)
                ->update(['paymentmethod' => $newPaymentMethod]);
        }
        gatewayfees_update_invoice(['invoiceid' => $invoiceId]);
    }
}

/* -------------------------------------------------------------------------
 * Hook registrations
 * ---------------------------------------------------------------------- */

// The fee function is idempotent (it removes the previous gateway_fees line
// before re-adding), so registering on every relevant hook is safe and never
// stacks fees. InvoiceCreationAdminArea is what fires on manual admin creation.
add_hook('InvoiceCreation', 1, 'gatewayfees_update_invoice');
add_hook('InvoiceCreationAdminArea', 1, 'gatewayfees_update_invoice');
add_hook('InvoiceCreationPreEmail', 1, 'gatewayfees_update_invoice');
add_hook('InvoiceCreated', 1, 'gatewayfees_update_invoice');
add_hook('InvoiceChangeGateway', 1, 'gatewayfees_update_invoice');

add_hook('ClientChangePaymentMethod', 1, function ($vars) {
    gatewayfees_update_client_invoices(
        $vars['userid'] ?? null,
        $vars['newpaymentmethod'] ?? ''
    );
});

add_hook('AdminClientProfileTabFieldsSave', 1, function ($vars) {
    $userid = $vars['userid'] ?? null;
    if (!$userid) {
        return;
    }
    $newPaymentMethod = Capsule::table('tblclients')
        ->where('id', $userid)
        ->value('defaultgateway');
    gatewayfees_update_client_invoices($userid, (string) $newPaymentMethod);
});

/**
 * The PayPal Payments (PPCP) gateway module names that create their PayPal
 * order in-context on the checkout page, before a WHMCS invoice exists.
 *
 * @return array
 */
function gatewayfees_ppcp_gateways()
{
    return ['paypal_ppcpv', 'paypal_acdc'];
}

/**
 * Web URL of the PPCP order-patch endpoint.
 *
 * @return string
 */
function gatewayfees_endpoint_url()
{
    $systemUrl = Capsule::table('tblconfiguration')
        ->where('setting', 'SystemURL')
        ->value('value');

    return rtrim((string) $systemUrl, '/') . '/modules/addons/gateway_fees/ppcp_patch.php';
}

/**
 * Checkout-page output: an optional visual fee estimate (Twenty-One theme) and
 * an optional PayPal Payments (PPCP) order-total patch.
 *
 * The visual estimate mirrors the server-side rules. The PPCP patch (opt-in)
 * intercepts the in-context PayPal order created before the invoice exists and
 * asks our server endpoint to add the configured fee to the remote order, so
 * the amount PayPal authorises matches the invoice total.
 */
add_hook('ShoppingCartCheckoutOutput', 1, function ($vars) {
    if (!Capsule::schema()->hasTable(GATEWAY_FEES_TABLE)) {
        return '';
    }

    $showEstimate = gatewayfees_setting('enable_checkout_hook', 'off') === 'on';
    $ppcpPatch    = gatewayfees_setting('enable_ppcp_patch', 'off') === 'on';

    if (!$showEstimate && !$ppcpPatch) {
        return '';
    }

    $output = '';

    if ($showEstimate) {
        // Current display currency.
        $currencySymbol = '';
        if (function_exists('getCurrency')) {
            $currency = getCurrency();
            if (!empty($currency['code'])) {
                $currencySymbol = $currency['code'];
            }
        }
        if (!$currencySymbol) {
            $currencySymbol = Capsule::table('tblcurrencies')
                ->where('default', 1)
                ->value('code');
        }
        $currencySymbol = htmlspecialchars((string) $currencySymbol, ENT_QUOTES);
        $feeLabel       = htmlspecialchars((string) gatewayfees_setting('fee_label', 'Gateway Fee'), ENT_QUOTES);

        // Build the fee map for enabled gateways.
        $rules = Capsule::table(GATEWAY_FEES_TABLE)->where('enabled', 1)->get();
        $fees  = [];
        foreach ($rules as $rule) {
            $fees[$rule->gateway] = [
                'fixed'   => (float) $rule->fee_fixed,
                'percent' => (float) $rule->fee_percent,
                'min'     => (float) ($rule->min_amount ?? 0),
                'max'     => (float) ($rule->max_fee ?? 0),
            ];
        }
        $feesJson = json_encode($fees);

        $output .= <<<EOT
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var fees = {$feesJson};
            var currency = "{$currencySymbol}";
            var label = "{$feeLabel}";

            var feeRow = '<div id="gatewayFeeRow">' + label + ': ' +
                         '<strong id="gatewayFee">0.00</strong> <strong>' + currency + '</strong></div>';
            var totalWithFeeRow = '<div id="totalWithFeeRow">Total with ' + label + ': ' +
                         '<strong id="totalWithFee">0.00</strong> <strong>' + currency + '</strong></div>';

            var checkoutSummary = document.getElementById('checkoutSummary');
            if (checkoutSummary) {
                checkoutSummary.insertAdjacentHTML('beforeend', feeRow + totalWithFeeRow);
            } else {
                var totalCartPriceElement = document.getElementById('totalCartPrice');
                if (totalCartPriceElement) {
                    totalCartPriceElement.insertAdjacentHTML('afterend', feeRow + totalWithFeeRow);
                }
            }

            function computeFee(rule, base) {
                if (rule.min > 0 && base < rule.min) { return 0; }
                var fee = rule.fixed + (base * rule.percent / 100);
                if (rule.max > 0 && Math.abs(fee) > rule.max) {
                    fee = (fee < 0 ? -1 : 1) * rule.max;
                }
                return fee;
            }

            function updateGatewayFee() {
                var selected = document.querySelector('input[name="paymentmethod"]:checked');
                if (!selected) { return; }
                var rule = fees[selected.value];

                var subtotalElement = document.getElementById('totalCartPrice');
                var subtotal = parseFloat(subtotalElement ? subtotalElement.textContent.replace(/[^\d.-]/g, '') : 0) || 0;

                var gatewayFee = rule ? computeFee(rule, subtotal) : 0;
                var totalWithFee = subtotal + gatewayFee;

                var feeEl = document.getElementById('gatewayFee');
                var totalEl = document.getElementById('totalWithFee');
                if (feeEl) { feeEl.textContent = gatewayFee.toFixed(2); }
                if (totalEl) { totalEl.textContent = totalWithFee.toFixed(2); }
            }

            var paymentMethods = document.querySelectorAll('input[name="paymentmethod"]');
            if (window.jQuery && jQuery().iCheck) {
                jQuery(paymentMethods).on('ifChecked', updateGatewayFee);
            } else {
                paymentMethods.forEach(function (m) {
                    m.addEventListener('change', updateGatewayFee);
                    m.addEventListener('click', updateGatewayFee);
                });
            }
            updateGatewayFee();
        });
    </script>
    EOT;
    }

    if ($ppcpPatch) {
        $endpoint     = htmlspecialchars(gatewayfees_endpoint_url(), ENT_QUOTES);
        $ppcpGateways = json_encode(array_values(gatewayfees_ppcp_gateways()));

        $output .= <<<EOT
    <script>
    (function () {
        // Opt-in: PayPal Payments (PPCP) creates its order in-context, before a
        // WHMCS invoice (and its fee) exists. We intercept that order-create
        // response and ask our server to add the configured fee to the remote
        // PayPal order. Everything here is best-effort and never blocks checkout.
        var endpoint = "{$endpoint}";
        var gateways = {$ppcpGateways};

        function matchGateway(url) {
            url = url || '';
            for (var i = 0; i < gateways.length; i++) {
                if (url.indexOf('/' + gateways[i] + '/order/create') !== -1) { return gateways[i]; }
            }
            return null;
        }

        function patchOrder(orderId, gateway) {
            if (!orderId || !gateway) { return; }
            try {
                var body = 'orderID=' + encodeURIComponent(orderId) + '&gateway=' + encodeURIComponent(gateway);
                fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body
                }).catch(function () {});
            } catch (e) {}
        }

        if (window.fetch) {
            var origFetch = window.fetch;
            window.fetch = function () {
                var args = arguments;
                var url = (args[0] && args[0].url) ? args[0].url : ('' + args[0]);
                var gw = matchGateway(url);
                var p = origFetch.apply(this, args);
                if (gw) {
                    p.then(function (resp) {
                        try {
                            resp.clone().json().then(function (data) {
                                if (data && data.id) { patchOrder(data.id, gw); }
                            }).catch(function () {});
                        } catch (e) {}
                    }).catch(function () {});
                }
                return p;
            };
        }

        try {
            var oOpen = XMLHttpRequest.prototype.open;
            var oSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) { this.__gwUrl = url; return oOpen.apply(this, arguments); };
            XMLHttpRequest.prototype.send = function () {
                var self = this;
                var gw = matchGateway(self.__gwUrl);
                if (gw) {
                    self.addEventListener('load', function () {
                        try {
                            var data = JSON.parse(self.responseText);
                            if (data && data.id) { patchOrder(data.id, gw); }
                        } catch (e) {}
                    });
                }
                return oSend.apply(this, arguments);
            };
        } catch (e) {}
    })();
    </script>
    EOT;
    }

    return $output;
});
