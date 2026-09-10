<?php

/**
 * WHMCS Gateway Fees Addon
 *
 * Adds fixed and/or percentage-based fees (or discounts) to invoices depending
 * on the payment gateway selected by the client.
 *
 * Compatible with WHMCS 9.x / PHP 8.1+.
 *
 * @package    WHMCS\Addon\GatewayFees
 * @author     Nikba Creative Studio
 * @copyright  Copyright (c) Nikba Creative Studio
 * @license    MIT
 * @link       https://github.com/Nikba-Creative-Studio/WHMCS-Gateway-Fees-Addon
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;
use Illuminate\Database\Schema\Blueprint;

const GATEWAY_FEES_VERSION = '2.2.0';

if (!defined('GATEWAY_FEES_TABLE')) {
    define('GATEWAY_FEES_TABLE', 'mod_gateway_fees');
}

/**
 * Addon configuration.
 *
 * Per-gateway fees are managed from the full admin interface (_output); the
 * config screen only exposes global behaviour options.
 *
 * @return array
 */
function gateway_fees_config()
{
    return [
        'name'        => 'Gateway Fees',
        'description' => 'Add fixed and/or percentage-based fees (or discounts) to invoices based on the payment gateway used. Manage per-gateway fees from the addon page.',
        'version'     => GATEWAY_FEES_VERSION,
        'author'      => 'Nikba Creative Studio',
        'language'    => 'english',
        'fields'      => [
            'fee_label' => [
                'FriendlyName' => 'Fee Label',
                'Type'         => 'text',
                'Size'         => '30',
                'Default'      => 'Gateway Fee',
                'Description'  => 'The label shown on the invoice line item and checkout page.',
            ],
            'fee_base' => [
                'FriendlyName' => 'Percentage Base',
                'Type'         => 'dropdown',
                'Options'      => 'subtotal,total',
                'Default'      => 'subtotal',
                'Description'  => '"subtotal" = item subtotal (excl. tax); "total" = subtotal + estimated tax.',
            ],
            'tax_fees' => [
                'FriendlyName' => 'Apply Tax to Fees',
                'Type'         => 'yesno',
                'Default'      => 'no',
                'Description'  => 'Tick to mark the gateway fee line item as taxable.',
            ],
            'exempt_groups' => [
                'FriendlyName' => 'Exempt Client Groups',
                'Type'         => 'text',
                'Size'         => '30',
                'Default'      => '',
                'Description'  => 'Comma-separated client group IDs that should never be charged a fee.',
            ],
            'log_activity' => [
                'FriendlyName' => 'Log to Activity Log',
                'Type'         => 'yesno',
                'Default'      => 'no',
                'Description'  => 'Tick to write an Activity Log entry each time a fee is applied.',
            ],
            'enable_checkout_hook' => [
                'FriendlyName' => 'Show Fees on Checkout Page',
                'Type'         => 'yesno',
                'Default'      => 'yes',
                'Description'  => 'Tick to display the estimated fee on the checkout page (Twenty-One theme).',
            ],
            'enable_ppcp_patch' => [
                'FriendlyName' => 'Adjust PayPal Payments Order Total (Beta)',
                'Type'         => 'yesno',
                'Default'      => 'no',
                'Description'  => 'For PayPal Payments (paypal_ppcpv / paypal_acdc) only. PPCP creates its PayPal order before the invoice exists, so the fee would not be charged. Tick to patch the PayPal order total to include the fee at checkout. Requires cURL and outbound access to api-m.paypal.com. Beta — test before enabling in production.',
            ],
        ],
    ];
}

/**
 * Activation: create/upgrade the fee storage table.
 *
 * @return array
 */
function gateway_fees_activate()
{
    try {
        gateway_fees_migrate_schema();
        gateway_fees_sync_gateways();

        return [
            'status'      => 'success',
            'description' => 'Gateway Fees activated. Configure per-gateway fees from the addon page.',
        ];
    } catch (\Throwable $e) {
        return [
            'status'      => 'error',
            'description' => 'Unable to activate Gateway Fees: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivation: keep data by default so fees survive a re-activation.
 *
 * @return array
 */
function gateway_fees_deactivate()
{
    return [
        'status'      => 'success',
        'description' => 'Gateway Fees deactivated. Your fee configuration has been preserved.',
    ];
}

/**
 * Upgrade routine. Runs automatically when the version increases.
 *
 * @param array $vars
 * @return void
 */
function gateway_fees_upgrade($vars)
{
    $installedVersion = $vars['version'] ?? '0';

    gateway_fees_migrate_schema();

    // 1.x -> 2.x: migrate legacy fee_1_* / fee_2_* settings.
    if (version_compare($installedVersion, '2.0', '<')) {
        $legacy = Capsule::table('tbladdonmodules')
            ->where('module', 'gateway_fees')
            ->where(function ($q) {
                $q->where('setting', 'like', 'fee_1_%')
                  ->orWhere('setting', 'like', 'fee_2_%');
            })
            ->get();

        $migrated = [];
        foreach ($legacy as $row) {
            if (strpos($row->setting, 'fee_1_') === 0) {
                $migrated[substr($row->setting, 6)]['fee_fixed'] = (float) $row->value;
            } elseif (strpos($row->setting, 'fee_2_') === 0) {
                $migrated[substr($row->setting, 6)]['fee_percent'] = (float) $row->value;
            }
        }

        foreach ($migrated as $gateway => $fees) {
            gateway_fees_save_gateway($gateway, [
                'fee_fixed'   => $fees['fee_fixed'] ?? 0,
                'fee_percent' => $fees['fee_percent'] ?? 0,
                'enabled'     => 1,
            ]);
        }

        Capsule::table('tbladdonmodules')
            ->where('module', 'gateway_fees')
            ->where(function ($q) {
                $q->where('setting', 'like', 'fee_1_%')
                  ->orWhere('setting', 'like', 'fee_2_%');
            })
            ->delete();
    }
}

/**
 * Admin area output — the full management interface.
 *
 * @param array $vars
 * @return void
 */
function gateway_fees_output($vars)
{
    $modulelink = $vars['modulelink'];
    $lang       = gateway_fees_lang($vars);
    $message    = '';

    $action = $_REQUEST['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        if (function_exists('check_token')) {
            check_token('WHMCSToken');
        }
        $message = gateway_fees_handle_save($lang);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'recalculate') {
        if (function_exists('check_token')) {
            check_token('WHMCSToken');
        }
        $message = gateway_fees_handle_recalculate($lang);
    }

    gateway_fees_migrate_schema();
    gateway_fees_sync_gateways();

    $currencyCode = (string) Capsule::table('tblcurrencies')->where('default', 1)->value('code');

    // Build view rows.
    $rows = [];
    $stored = Capsule::table(GATEWAY_FEES_TABLE)->orderBy('gateway')->get();
    foreach ($stored as $row) {
        $rows[] = [
            'gateway'     => $row->gateway,
            'friendly'    => gateway_fees_friendly_name($row->gateway),
            'enabled'     => (bool) $row->enabled,
            'fee_fixed'   => gateway_fees_num($row->fee_fixed),
            'fee_percent' => gateway_fees_num($row->fee_percent),
            'min_amount'  => gateway_fees_num($row->min_amount ?? 0),
            'max_fee'     => gateway_fees_num($row->max_fee ?? 0),
            'updated'     => $row->updated_at ?: ($lang['never'] ?? 'never'),
        ];
    }

    $stats = gateway_fees_stats();

    echo gateway_fees_render_admin([
        'modulelink'   => $modulelink,
        'rows'         => $rows,
        'currencyCode' => $currencyCode,
        'message'      => $message,
        'stats'        => $stats,
        'L'            => $lang,
    ]);
}

/* -------------------------------------------------------------------------
 * Schema & data helpers
 * ---------------------------------------------------------------------- */

/**
 * Create the fee table and add any missing columns (idempotent).
 *
 * @return void
 */
function gateway_fees_migrate_schema()
{
    $schema = Capsule::schema();

    if (!$schema->hasTable(GATEWAY_FEES_TABLE)) {
        $schema->create(GATEWAY_FEES_TABLE, function (Blueprint $table) {
            $table->increments('id');
            $table->string('gateway', 128)->unique();
            $table->decimal('fee_fixed', 16, 2)->default(0);
            $table->decimal('fee_percent', 8, 4)->default(0);
            $table->decimal('min_amount', 16, 2)->default(0);
            $table->decimal('max_fee', 16, 2)->default(0);
            $table->unsignedTinyInteger('enabled')->default(1);
            $table->timestamp('updated_at')->nullable();
        });
        return;
    }

    // Add columns introduced after the initial 2.0 table.
    $schema->table(GATEWAY_FEES_TABLE, function (Blueprint $table) use ($schema) {
        if (!$schema->hasColumn(GATEWAY_FEES_TABLE, 'min_amount')) {
            $table->decimal('min_amount', 16, 2)->default(0);
        }
        if (!$schema->hasColumn(GATEWAY_FEES_TABLE, 'max_fee')) {
            $table->decimal('max_fee', 16, 2)->default(0);
        }
    });
}

/**
 * Ensure every installed payment gateway has a row in the fee table.
 *
 * @return void
 */
function gateway_fees_sync_gateways()
{
    if (!Capsule::schema()->hasTable(GATEWAY_FEES_TABLE)) {
        return;
    }

    $gateways = Capsule::table('tblpaymentgateways')
        ->select('gateway')
        ->groupBy('gateway')
        ->pluck('gateway');

    $existing = Capsule::table(GATEWAY_FEES_TABLE)->pluck('gateway')->all();

    foreach ($gateways as $gateway) {
        if (!in_array($gateway, $existing, true)) {
            Capsule::table(GATEWAY_FEES_TABLE)->insert([
                'gateway'     => $gateway,
                'fee_fixed'   => 0,
                'fee_percent' => 0,
                'min_amount'  => 0,
                'max_fee'     => 0,
                'enabled'     => 1,
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }
    }
}

/**
 * Insert or update a gateway fee row.
 *
 * @param string $gateway
 * @param array  $data
 * @return void
 */
function gateway_fees_save_gateway($gateway, array $data)
{
    $payload = [
        'fee_fixed'   => round((float) ($data['fee_fixed'] ?? 0), 2),
        'fee_percent' => round((float) ($data['fee_percent'] ?? 0), 4),
        'min_amount'  => round(max(0, (float) ($data['min_amount'] ?? 0)), 2),
        'max_fee'     => round(max(0, (float) ($data['max_fee'] ?? 0)), 2),
        'enabled'     => !empty($data['enabled']) ? 1 : 0,
        'updated_at'  => date('Y-m-d H:i:s'),
    ];

    if (Capsule::table(GATEWAY_FEES_TABLE)->where('gateway', $gateway)->exists()) {
        Capsule::table(GATEWAY_FEES_TABLE)->where('gateway', $gateway)->update($payload);
    } else {
        $payload['gateway'] = $gateway;
        Capsule::table(GATEWAY_FEES_TABLE)->insert($payload);
    }
}

/**
 * Statistics for the overview panel: unpaid invoices carrying a fee and the
 * total of those fees.
 *
 * @return array
 */
function gateway_fees_stats()
{
    $q = Capsule::table('tblinvoiceitems')
        ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
        ->where('tblinvoiceitems.notes', 'gateway_fees')
        ->where('tblinvoices.status', 'Unpaid');

    return [
        'invoices'  => (int) (clone $q)->distinct()->count('tblinvoiceitems.invoiceid'),
        'collected' => (float) (clone $q)->sum('tblinvoiceitems.amount'),
    ];
}

/* -------------------------------------------------------------------------
 * Form handlers
 * ---------------------------------------------------------------------- */

/**
 * Process the saved fee table from the admin form.
 *
 * @param array $lang
 * @return string HTML status message.
 */
function gateway_fees_handle_save(array $lang)
{
    $fixed   = $_POST['fee_fixed']   ?? [];
    $percent = $_POST['fee_percent'] ?? [];
    $minAmt  = $_POST['min_amount']  ?? [];
    $maxFee  = $_POST['max_fee']     ?? [];
    $enabled = $_POST['enabled']     ?? [];

    $count = 0;
    foreach ($fixed as $gateway => $value) {
        gateway_fees_save_gateway($gateway, [
            'fee_fixed'   => $value,
            'fee_percent' => $percent[$gateway] ?? 0,
            'min_amount'  => $minAmt[$gateway] ?? 0,
            'max_fee'     => $maxFee[$gateway] ?? 0,
            'enabled'     => !empty($enabled[$gateway]) ? 1 : 0,
        ]);
        $count++;
    }

    $msg = gateway_fees_alert('success', sprintf($lang['saved'] ?? 'Saved %d gateway(s).', $count));

    if (!empty($_POST['apply_existing'])) {
        $msg .= gateway_fees_handle_recalculate($lang);
    }

    return $msg;
}

/**
 * Recalculate the gateway fee on all unpaid invoices.
 *
 * @param array $lang
 * @return string HTML status message.
 */
function gateway_fees_handle_recalculate(array $lang)
{
    if (!function_exists('gatewayfees_update_invoice')) {
        return gateway_fees_alert('danger', $lang['hooks_missing'] ?? 'Hook functions are not loaded.');
    }

    $invoices = Capsule::table('tblinvoices')->where('status', 'Unpaid')->pluck('id');
    foreach ($invoices as $invoiceId) {
        gatewayfees_update_invoice(['invoiceid' => $invoiceId]);
    }

    return gateway_fees_alert('success', sprintf($lang['recalc_done'] ?? 'Recalculated %d invoice(s).', count($invoices)));
}

/* -------------------------------------------------------------------------
 * Presentation helpers
 * ---------------------------------------------------------------------- */

/**
 * Load the active language strings for the admin view.
 *
 * @param array $vars
 * @return array
 */
function gateway_fees_lang($vars)
{
    if (!empty($vars['_lang']) && is_array($vars['_lang'])) {
        return $vars['_lang'];
    }
    global $_ADDONLANG;
    return is_array($_ADDONLANG ?? null) ? $_ADDONLANG : [];
}

/**
 * Format a numeric value for display, trimming trailing zeros.
 *
 * @param mixed $value
 * @return string
 */
function gateway_fees_num($value)
{
    $s = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/**
 * Build a Bootstrap alert box.
 *
 * @param string $type
 * @param string $text
 * @return string
 */
function gateway_fees_alert($type, $text)
{
    return '<div class="alert alert-' . $type . '">' . htmlspecialchars($text) . '</div>';
}

/**
 * Resolve the friendly display name of a gateway.
 *
 * @param string $gateway
 * @return string
 */
function gateway_fees_friendly_name($gateway)
{
    $name = Capsule::table('tblpaymentgateways')
        ->where('gateway', $gateway)
        ->where('setting', 'name')
        ->value('value');

    return $name ?: ucfirst($gateway);
}

/**
 * Render the admin management interface from the template.
 *
 * WHMCS automatically injects a hidden CSRF token into admin-area POST forms,
 * validated by check_token('WHMCSToken').
 *
 * @param array $data
 * @return string
 */
function gateway_fees_render_admin(array $data)
{
    extract($data, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/templates/manage.tpl.php';
    return ob_get_clean();
}
