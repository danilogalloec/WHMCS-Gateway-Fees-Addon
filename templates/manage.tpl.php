<?php

/**
 * Gateway Fees — admin management view.
 *
 * Rendered by gateway_fees_render_admin() via output buffering.
 *
 * Available variables:
 * @var string $modulelink
 * @var array  $rows          List of view rows (associative arrays).
 * @var string $currencyCode
 * @var string $message       Pre-rendered alert HTML (may be empty).
 * @var array  $stats         ['invoices' => int, 'collected' => float]
 * @var array  $L             Language strings (key => translated text).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
?>
<div class="gateway-fees-addon">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:15px;">
        <h2 style="margin:0;"><?= $e($L['heading']) ?> <small>v<?= $e(GATEWAY_FEES_VERSION) ?></small></h2>
        <a href="https://buymeacoffee.com/nikba" target="_blank" rel="noopener" class="btn btn-default btn-sm">&#9749; <?= $e($L['coffee']) ?></a>
    </div>

    <?= $message ?>

    <div class="row" style="margin-bottom:15px;">
        <div class="col-sm-6">
            <div class="panel panel-default" style="margin-bottom:0;">
                <div class="panel-body text-center">
                    <div style="font-size:24px;font-weight:bold;"><?= (int) $stats['invoices'] ?></div>
                    <div class="text-muted"><?= $e($L['stats_invoices']) ?></div>
                </div>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="panel panel-default" style="margin-bottom:0;">
                <div class="panel-body text-center">
                    <div style="font-size:24px;font-weight:bold;"><?= $e(number_format($stats['collected'], 2)) ?> <?= $e($currencyCode) ?></div>
                    <div class="text-muted"><?= $e($L['stats_collected']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-info"><?= $e($L['intro']) ?></div>

    <form method="post" action="<?= $e($modulelink) ?>&action=save">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center;"><?= $e($L['col_on']) ?></th>
                    <th><?= $e($L['col_gateway']) ?></th>
                    <th style="width:160px;"><?= $e($L['col_fixed']) ?> (<?= $e($currencyCode) ?>)</th>
                    <th style="width:150px;"><?= $e($L['col_percent']) ?></th>
                    <th style="width:150px;" title="<?= $e($L['hint_min']) ?>"><?= $e($L['col_min']) ?></th>
                    <th style="width:150px;" title="<?= $e($L['hint_max']) ?>"><?= $e($L['col_max']) ?></th>
                    <th style="width:140px;"><?= $e($L['col_updated']) ?></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="text-center"><?= $e($L['no_gateways']) ?></td></tr>
            <?php else: foreach ($rows as $r): $g = $e($r['gateway']); ?>
                <tr>
                    <td style="text-align:center;vertical-align:middle;">
                        <input type="checkbox" name="enabled[<?= $g ?>]" value="1" <?= $r['enabled'] ? 'checked' : '' ?> />
                    </td>
                    <td style="vertical-align:middle;">
                        <strong><?= $e($r['friendly']) ?></strong>
                        <span class="text-muted">(<?= $g ?>)</span>
                    </td>
                    <td>
                        <div class="input-group">
                            <span class="input-group-addon"><?= $e($currencyCode) ?></span>
                            <input type="number" step="0.01" class="form-control" name="fee_fixed[<?= $g ?>]" value="<?= $e($r['fee_fixed']) ?>" />
                        </div>
                    </td>
                    <td>
                        <div class="input-group">
                            <input type="number" step="0.0001" class="form-control" name="fee_percent[<?= $g ?>]" value="<?= $e($r['fee_percent']) ?>" />
                            <span class="input-group-addon">%</span>
                        </div>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" class="form-control" name="min_amount[<?= $g ?>]" value="<?= $e($r['min_amount']) ?>" title="<?= $e($L['hint_min']) ?>" />
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" class="form-control" name="max_fee[<?= $g ?>]" value="<?= $e($r['max_fee']) ?>" title="<?= $e($L['hint_max']) ?>" />
                    </td>
                    <td style="vertical-align:middle;" class="text-muted">
                        <small><?= $e($r['updated']) ?></small>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <label style="font-weight:normal;margin-right:15px;">
            <input type="checkbox" name="apply_existing" value="1" /> <?= $e($L['apply_existing']) ?>
        </label>
        <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= $e($L['save']) ?></button>
    </form>

    <hr />

    <form method="post" action="<?= $e($modulelink) ?>&action=recalculate" onsubmit="return confirm('<?= $e($L['recalc_confirm']) ?>');">
        <h4><?= $e($L['maintenance']) ?></h4>
        <p class="text-muted"><?= $e($L['maintenance_desc']) ?></p>
        <button type="submit" class="btn btn-default"><i class="fa fa-refresh"></i> <?= $e($L['recalculate']) ?></button>
    </form>
</div>
