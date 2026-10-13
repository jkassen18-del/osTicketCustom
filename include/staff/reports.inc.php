<?php
// $builder, $result, $table, $presets - set by reports.php
$p = $builder->getParams();
$depts = Dept::getDepartments();
$agents = Staff::getStaffMembers();
$defaults = ReportBuilder::defaultPresets();
$chartType = $result['chart'] == 'stacked' ? 'stacked' : $result['chart'];
$series = array();
foreach ($result['series'] as $name => $values)
    $series[] = array('name' => $name, 'values' => $values);
?>
<script type="text/javascript" src="js/charts.js?v=<?php echo urlencode(GIT_VERSION); ?>"></script>
<div class="sticky bar opaque">
    <div class="content">
        <div class="pull-left flush-left">
            <h2><?php echo __('Reports'); ?> <i class="help-tip icon-question-sign" href="#reports"></i></h2>
        </div>
        <div class="pull-right flush-right">
            <a href="dashboard.php" class="action-button"><i class="icon-dashboard"></i> <?php echo __('Dashboard'); ?></a>
        </div>
    </div>
</div>
<div class="clear"></div>

<form method="get" action="reports.php" id="report-form" class="filter-bar" style="align-items:flex-end">
    <label><?php echo __('Metric'); ?>
        <select name="metric">
<?php foreach (ReportBuilder::$metrics as $k => $v) { ?>
            <option value="<?php echo $k; ?>" <?php if ($p['metric'] == $k) echo 'selected'; ?>><?php echo __($v); ?></option>
<?php } ?>
        </select></label>
    <label><?php echo __('Split by'); ?>
        <select name="split">
<?php foreach (ReportBuilder::$splits as $k => $v) { ?>
            <option value="<?php echo $k; ?>" <?php if ($p['split'] == $k) echo 'selected'; ?>><?php echo __($v); ?></option>
<?php } ?>
        </select></label>
    <label><?php echo __('Per'); ?>
        <select name="period">
<?php foreach (ReportBuilder::$periods as $k => $v) { ?>
            <option value="<?php echo $k; ?>" <?php if ($p['period'] == $k) echo 'selected'; ?>><?php echo __($v); ?></option>
<?php } ?>
        </select></label>
    <label><?php echo __('From'); ?> <input type="date" name="from" value="<?php echo Format::htmlchars($p['from']); ?>"></label>
    <label><?php echo __('To'); ?> <input type="date" name="to" value="<?php echo Format::htmlchars($p['to']); ?>"></label>
    <label><?php echo __('Department'); ?>
        <select name="dept[]" multiple size="1" style="min-width:140px;height:34px">
<?php foreach ($depts as $id => $name) { ?>
            <option value="<?php echo $id; ?>" <?php if (in_array($id, $p['dept'])) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
        </select></label>
    <label><?php echo __('Agent'); ?>
        <select name="staff_id">
            <option value="0"><?php echo __('Any'); ?></option>
<?php foreach ($agents as $id => $name) { ?>
            <option value="<?php echo $id; ?>" <?php if ($p['staff_id'] == $id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
        </select></label>
    <label><?php echo __('Chart'); ?>
        <select name="chart">
<?php foreach (ReportBuilder::$charts as $k => $v) { ?>
            <option value="<?php echo $k; ?>" <?php if ($p['chart'] == $k) echo 'selected'; ?>><?php echo __($v); ?></option>
<?php } ?>
        </select></label>
    <label><?php echo __('Series'); ?>
        <input type="number" name="limit" min="2" max="12" value="<?php echo (int) $p['limit']; ?>" style="width:64px"></label>
    <button type="submit" class="button"><i class="icon-play"></i> <?php echo __('Run'); ?></button>
</form>

<div class="card-grid">
    <div class="card wide">
        <div class="card-head">
            <h3><?php echo Format::htmlchars($result['title']); ?> <span class="faded" style="font-weight:400">· <?php echo Format::htmlchars($result['range']); ?></span></h3>
            <div class="card-tools">
                <form method="post" action="reports.php" style="display:inline-flex;gap:6px;align-items:center">
                    <?php csrf_token(); ?>
<?php foreach (array('metric', 'split', 'period', 'from', 'to', 'staff_id', 'chart', 'limit') as $k) { ?>
                    <input type="hidden" name="<?php echo $k; ?>" value="<?php echo Format::htmlchars($p[$k]); ?>">
<?php }
foreach ($p['dept'] as $d) { ?>
                    <input type="hidden" name="dept[]" value="<?php echo (int) $d; ?>">
<?php } ?>
                    <input type="text" name="preset_name" placeholder="<?php echo __('Save as…'); ?>" style="min-height:30px;width:150px">
                    <button type="submit" name="do" value="save-preset" class="action-button" style="height:30px !important"><i class="icon-save"></i> <?php echo __('Save'); ?></button>
                    <button type="submit" name="do" value="export" class="action-button" style="height:30px !important"><i class="icon-download"></i> <?php echo __('CSV'); ?></button>
                </form>
            </div>
        </div>
        <div class="chart" id="chart-report" style="min-height:300px"></div>
    </div>

    <div class="card">
        <div class="card-head"><h3><?php echo __('Saved reports'); ?></h3></div>
<?php if ($presets) { ?>
        <ul class="bar-list">
<?php   foreach ($presets as $name => $preset) { ?>
            <li style="grid-template-columns:1fr auto">
                <span class="lbl"><a href="reports.php?preset=<?php echo urlencode($name); ?>"><?php echo Format::htmlchars($name); ?></a>
                    <span class="faded small">· <?php echo __(ReportBuilder::$metrics[$preset['metric']] ?? ''); ?><?php if ($preset['split']) echo ' / ' . __(ReportBuilder::$splits[$preset['split']]); ?></span></span>
                <form method="post" action="reports.php" style="display:inline">
                    <?php csrf_token(); ?>
                    <input type="hidden" name="preset_name" value="<?php echo Format::htmlchars($name); ?>">
<?php       foreach (array('metric', 'split', 'period', 'from', 'to', 'staff_id', 'chart', 'limit') as $k) { ?>
                    <input type="hidden" name="<?php echo $k; ?>" value="<?php echo Format::htmlchars($p[$k]); ?>">
<?php       } ?>
                    <button type="submit" name="do" value="delete-preset" class="link" title="<?php echo __('Remove'); ?>"><i class="icon-remove"></i></button>
                </form>
            </li>
<?php   } ?>
        </ul>
<?php } else { ?>
        <p class="faded"><?php echo __('Run a report and save it with a name to find it here.'); ?></p>
<?php } ?>
        <div class="faded small" style="margin:10px 0 4px"><?php echo __('Suggestions'); ?></div>
        <ul class="bar-list">
<?php foreach ($defaults as $name => $preset) { ?>
            <li style="grid-template-columns:1fr"><span class="lbl"><a href="reports.php?preset=<?php echo urlencode($name); ?>"><?php echo Format::htmlchars($name); ?></a></span></li>
<?php } ?>
        </ul>
    </div>

    <div class="card">
        <div class="card-head"><h3><?php echo __('Totals'); ?></h3>
            <div class="card-tools"><span class="faded small"><?php
                echo $result['unit'] == 'count' ? __('sum') : ($result['unit'] == 'percent' ? __('average %') : __('average hours')); ?></span></div></div>
<?php if (!$result['totals']) { ?>
        <div class="chart-empty"><?php echo __('No data for this selection'); ?></div>
<?php } else {
        $maxTotal = max(1, max(array_map('floatval', $result['totals']))); ?>
        <ul class="bar-list">
<?php   foreach ($result['totals'] as $name => $total) { ?>
            <li>
                <span class="lbl" title="<?php echo Format::htmlchars($name); ?>"><?php echo Format::htmlchars($name); ?></span>
                <span class="bar"><span style="width:<?php echo $total === null ? 0 : round((float) $total / $maxTotal * 100); ?>%"></span></span>
                <span class="num"><?php echo $total === null ? '—' : $total . ($result['unit'] == 'percent' ? '%' : ($result['unit'] == 'hours' ? 'h' : '')); ?></span>
            </li>
<?php   } ?>
        </ul>
<?php } ?>
    </div>

    <div class="card wide">
        <div class="card-head"><h3><?php echo __('Data'); ?></h3></div>
        <div style="overflow:auto">
        <table class="list report-table" cellspacing="0" cellpadding="0">
            <thead><tr>
<?php foreach ($table['columns'] as $i => $c) { ?>
                <th class="<?php echo $i ? 'num' : ''; ?>"><?php echo Format::htmlchars($c); ?></th>
<?php } ?>
            </tr></thead>
            <tbody>
<?php foreach ($table['rows'] as $row) { ?>
                <tr>
<?php   foreach ($row as $i => $cell) { ?>
                    <td class="<?php echo $i ? 'num' : ''; ?>"><?php echo Format::htmlchars($cell === '' ? '—' : $cell); ?></td>
<?php   } ?>
                </tr>
<?php }
if (!$table['rows']) { ?>
                <tr><td colspan="<?php echo count($table['columns']); ?>"><em class="faded"><?php echo __('No data for this selection'); ?></em></td></tr>
<?php } ?>
            </tbody>
            <tfoot><tr>
<?php foreach ($table['totals'] as $i => $cell) { ?>
                <td class="<?php echo $i ? 'num' : ''; ?>"><b><?php echo Format::htmlchars($cell === '' ? '—' : $cell); ?></b></td>
<?php } ?>
            </tr></tfoot>
        </table>
        </div>
    </div>
</div>

<script type="text/javascript">
$(function() {
    OstCharts.render('#chart-report', {
        type: <?php echo json_encode($chartType); ?>,
        unit: <?php echo json_encode($result['unit']); ?>,
        height: 300,
        labels: <?php echo json_encode($result['labels']); ?>,
        series: <?php echo json_encode($series); ?>,
        emptyLabel: <?php echo json_encode(__('No data for this selection')); ?>
    });
});
</script>
