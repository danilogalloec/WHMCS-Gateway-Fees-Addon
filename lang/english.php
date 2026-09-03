<?php

/**
 * Gateway Fees — English language strings.
 *
 * WHMCS automatically loads this file into the $_ADDONLANG global when the
 * addon is active. Copy this file (e.g. to lang/spanish.php) to translate.
 */

$_ADDONLANG['heading']            = 'Gateway Fees';
$_ADDONLANG['intro']              = 'Configure a fixed amount, a percentage, or both for each gateway. Negative values act as discounts. The percentage is applied to the configured base. Disabled gateways are skipped entirely.';
$_ADDONLANG['coffee']             = 'Buy me a Coffee';

$_ADDONLANG['col_on']             = 'On';
$_ADDONLANG['col_gateway']        = 'Payment Gateway';
$_ADDONLANG['col_fixed']          = 'Fixed Fee';
$_ADDONLANG['col_percent']        = 'Percentage Fee (%)';
$_ADDONLANG['col_min']            = 'Min. Amount';
$_ADDONLANG['col_max']            = 'Max. Fee';
$_ADDONLANG['col_updated']        = 'Updated';

$_ADDONLANG['hint_min']           = 'Only apply the fee when the invoice base is at or above this amount (0 = always).';
$_ADDONLANG['hint_max']           = 'Cap the fee at this amount (0 = no cap).';

$_ADDONLANG['no_gateways']        = 'No payment gateways found. Activate a gateway under Setup » Payment Gateways first.';
$_ADDONLANG['save']               = 'Save Changes';
$_ADDONLANG['apply_existing']     = 'Also re-apply to existing unpaid invoices';

$_ADDONLANG['maintenance']        = 'Maintenance';
$_ADDONLANG['maintenance_desc']   = 'Re-apply the current fee rules to every unpaid invoice.';
$_ADDONLANG['recalculate']        = 'Recalculate Unpaid Invoices';
$_ADDONLANG['recalc_confirm']     = 'Recalculate gateway fees on all unpaid invoices now?';

$_ADDONLANG['stats_heading']      = 'Overview';
$_ADDONLANG['stats_invoices']     = 'Unpaid invoices with a fee';
$_ADDONLANG['stats_collected']    = 'Fees on open invoices';

$_ADDONLANG['saved']              = 'Saved fee settings for %d gateway(s).';
$_ADDONLANG['recalc_done']        = 'Recalculated fees on %d unpaid invoice(s).';
$_ADDONLANG['hooks_missing']      = 'Hook functions are not loaded.';
$_ADDONLANG['never']              = 'never';
