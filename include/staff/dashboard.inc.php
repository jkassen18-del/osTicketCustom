<?php
// $dashboard - <DashboardReport> (set by dashboard.php)
$report = $dashboard->getOverview();
$prefs = $dashboard->getPrefs();
$widgets = array_flip($prefs['widgets']);
$show = function($w) use ($widgets) { return isset($widgets[$w]); };
$kpis = $show('kpis') || $show('sla') ? $dashboard->getKpis() : null;
$breakdownBy = isset($_GET['breakdown']) && isset(DashboardReport::$breakdowns[$_GET['breakdown']])
    ? $_GET['breakdown'] : $prefs['breakdown'];
$chartType = isset($_GET['chart']) && in_array($_GET['chart'], array('line', 'bar', 'area')) ? $_GET['chart'] : $prefs['chart'];
$activity = $show('activity') ? $dashboard->getActivitySeries() : null;
$breakdown = $show('breakdown') ? $dashboard->getBreakdown($breakdownBy) : null;
$workload = $show('load') ? $dashboard->getWorkload() : null;
$mywork = $show('mywork') ? $dashboard->getMyWork() : null;
$recent = $show('recent') ? $dashboard->getRecentActivity() : null;
$health = $show('health') ? $dashboard->getIntegrationHealth() : null;
$qs = array();
foreach (array('start', 'period') as $k)
    if (!empty($_REQUEST[$k])) $qs[$k] = $_REQUEST[$k];
$hours = function($v) { return $v === null ? '—' : sprintf('%sh', rtrim(rtrim(number_format($v, 1), '0'), '.')); };
?>
<script type="text/javascript" src="js/charts.js?v=<?php echo urlencode(GIT_VERSION); ?>"></script>

<div class="dash-toolbar">
    <form method="get" action="dashboard.php">
        <label class="faded"><?php echo __('Report timeframe'); ?>:
            <input type="text" class="dp" name="start" placeholder="<?php echo __('Last month'); ?>"
                value="<?php echo Format::htmlchars($report->getStartDate()); ?>" style="width:130px"></label>
        <select name="period">
<?php foreach ($report::$end_choices as $val => $desc) { ?>
            <option value="<?php echo $val; ?>" <?php if ($report->end == $val) echo 'selected'; ?>><?php echo __($desc); ?></option>
<?php } ?>
        </select>
        <input type="hidden" name="breakdown" value="<?php echo Format::htmlchars($breakdownBy); ?>">
        <input type="hidden" name="chart" value="<?php echo Format::htmlchars($chartType); ?>">
        <button class="button" type="submit"><i class="icon-refresh"></i> <?php echo __('Refresh'); ?></button>
        <i class="help-tip icon-question-sign" href="#report_timeframe"></i>
    </form>
    <span class="spacer"></span>
    <span class="faded"><?php echo Format::htmlchars($dashboard->getRangeLabel()); ?></span>
    <a href="reports.php" class="action-button"><i class="icon-bar-chart"></i> <?php echo __('Reports'); ?></a>
    <span class="action-button" data-dropdown="#dash-customize"><i class="icon-caret-down pull-right"></i> <i class="icon-th-large"></i> <?php echo __('Customize'); ?></span>
    <div id="dash-customize" class="action-dropdown anchor-right" style="min-width:260px">
        <form method="post" action="dashboard.php" style="padding:8px 10px">
            <?php csrf_token(); ?>
            <input type="hidden" name="prefs" value="1">
            <div class="faded small" style="margin-bottom:6px"><?php echo __('Widgets'); ?></div>
            <div class="widget-toggles">
<?php foreach (DashboardReport::$widgets as $key => $label) { ?>
                <label class="<?php echo $show($key) ? 'on' : ''; ?>"><input type="checkbox" name="widgets[]" value="<?php echo $key; ?>" <?php if ($show($key)) echo 'checked'; ?> style="display:none"> <?php echo __($label); ?></label>
<?php } ?>
            </div>
            <div class="faded small" style="margin:10px 0 4px"><?php echo __('Activity chart'); ?></div>
            <select name="chart" style="width:100%">
<?php foreach (array('line' => __('Line'), 'area' => __('Area'), 'bar' => __('Bars')) as $k => $v) { ?>
                <option value="<?php echo $k; ?>" <?php if ($chartType == $k) echo 'selected'; ?>><?php echo $v; ?></option>
<?php } ?>
            </select>
            <div class="faded small" style="margin:10px 0 4px"><?php echo __('Backlog breakdown'); ?></div>
            <select name="breakdown" style="width:100%">
<?php foreach (DashboardReport::$breakdowns as $k => $v) { ?>
                <option value="<?php echo $k; ?>" <?php if ($breakdownBy == $k) echo 'selected'; ?>><?php echo __($v); ?></option>
<?php } ?>
            </select>
            <div style="margin-top:10px;text-align:right"><input type="submit" value="<?php echo __('Save layout'); ?>"></div>
        </form>
    </div>
</div>

<?php if ($show('kpis')) { ?>
<div class="kpi-row">
    <a class="kpi kpi-accent" href="tickets.php?queue=1">
        <span class="kpi-value"><?php echo $kpis['open']; ?></span>
        <span class="kpi-label"><?php echo __('Open'); ?></span>
        <span class="kpi-sub"><?php echo sprintf(__('%d awaiting reply'), $kpis['awaiting']); ?></span>
    </a>
    <a class="kpi <?php if ($kpis['overdue']) echo 'kpi-danger'; ?>" href="tickets.php?queue=4">
        <span class="kpi-value"><?php echo $kpis['overdue']; ?></span>
        <span class="kpi-label"><?php echo __('Overdue'); ?></span>
        <span class="kpi-sub"><?php echo sprintf(__('%d due in 24h'), $kpis['due_today']); ?></span>
    </a>
    <a class="kpi <?php if ($kpis['unassigned']) echo 'kpi-warn'; ?>" href="tickets.php?queue=2">
        <span class="kpi-value"><?php echo $kpis['unassigned']; ?></span>
        <span class="kpi-label"><?php echo __('Unassigned'); ?></span>
        <span class="kpi-sub"><?php echo sprintf(__('%d assigned to me'), $kpis['mine']); ?></span>
    </a>
    <div class="kpi">
        <span class="kpi-value"><?php echo $kpis['created']; ?> <span class="faded" style="font-size:14px">/ <?php echo $kpis['closed']; ?></span></span>
        <span class="kpi-label"><?php echo __('Created / closed'); ?></span>
        <span class="kpi-sub"><?php echo __('in timeframe'); ?></span>
    </div>
    <div class="kpi">
        <span class="kpi-value"><?php echo $hours($kpis['response_hours']); ?></span>
        <span class="kpi-label"><?php echo __('First response'); ?></span>
        <span class="kpi-sub"><?php echo sprintf(__('%s to close'), $hours($kpis['service_hours'])); ?></span>
    </div>
    <div class="kpi <?php if ($kpis['sla']['percent'] !== null && $kpis['sla']['percent'] < 90) echo 'kpi-warn'; ?>">
        <span class="kpi-value"><?php echo $kpis['sla']['percent'] === null ? '—' : $kpis['sla']['percent'] . '%'; ?></span>
        <span class="kpi-label"><?php echo __('SLA met'); ?></span>
        <span class="kpi-sub"><?php echo sprintf(__('%d breached · %d at risk (7d)'), $kpis['sla']['breached'], $kpis['at_risk']); ?></span>
    </div>
</div>
<?php } ?>

<div class="card-grid">
<?php if ($show('activity')) { ?>
    <div class="card wide">
        <div class="card-head">
            <h3><?php echo __('Ticket Activity'); ?> <i class="help-tip icon-question-sign" href="#ticket_activity"></i></h3>
            <div class="card-tools segmented">
<?php foreach (array('line' => __('Line'), 'area' => __('Area'), 'bar' => __('Bars')) as $k => $v) {
        $q = $qs + array('chart' => $k, 'breakdown' => $breakdownBy); ?>
                <a href="dashboard.php?<?php echo Http::build_query($q); ?>" class="<?php if ($chartType == $k) echo 'active'; ?>"><?php echo $v; ?></a>
<?php } ?>
            </div>
        </div>
        <div class="chart" id="chart-activity" style="min-height:260px"></div>
    </div>
<?php } ?>

<?php if ($show('load')) { ?>
    <div class="card">
        <div class="card-head"><h3><?php echo __('Workload by department'); ?></h3>
            <div class="card-tools"><span class="faded small"><?php echo __('open · unassigned · overdue'); ?></span></div></div>
<?php   if (!$workload) { ?>
        <div class="chart-empty"><?php echo __('No open tickets'); ?></div>
<?php   } else {
            $max = max(1, max(array_map(function($r) { return $r['open']; }, $workload))); ?>
        <ul class="bar-list">
<?php       foreach ($workload as $r) { ?>
            <li>
                <span class="lbl"><a href="tickets.php?a=search&status=open&dept_id=<?php echo $r['dept_id']; ?>"><?php echo Format::htmlchars($r['name']); ?></a></span>
                <span class="bar" title="<?php echo sprintf(__('%d open'), $r['open']); ?>"><span style="width:<?php echo round($r['open'] / $max * 100); ?>%"></span></span>
                <span class="num"><?php echo $r['open']; ?> · <?php echo $r['unassigned']; ?> · <span class="<?php if ($r['overdue']) echo 'error'; ?>"><?php echo $r['overdue']; ?></span></span>
            </li>
<?php       } ?>
        </ul>
<?php   } ?>
    </div>
<?php } ?>

<?php if ($show('breakdown')) { ?>
    <div class="card">
        <div class="card-head"><h3><?php echo __('Backlog by'); ?> <?php echo __(DashboardReport::$breakdowns[$breakdownBy]); ?></h3>
            <div class="card-tools segmented">
<?php   foreach (array('status', 'priority', 'source', 'dept') as $k) { $q = $qs + array('breakdown' => $k, 'chart' => $chartType); ?>
                <a href="dashboard.php?<?php echo Http::build_query($q); ?>" class="<?php if ($breakdownBy == $k) echo 'active'; ?>"><?php echo __(DashboardReport::$breakdowns[$k]); ?></a>
<?php   } ?>
            </div>
        </div>
        <div class="chart" id="chart-breakdown" style="min-height:220px"></div>
    </div>
<?php } ?>

<?php if ($show('sla')) { ?>
    <div class="card">
        <div class="card-head"><h3><?php echo __('SLA compliance'); ?></h3>
            <div class="card-tools"><a href="reports.php?metric=sla&split=dept&period=week&chart=line"><?php echo __('Report'); ?> &rarr;</a></div></div>
        <div style="display:flex;align-items:center;gap:18px;flex-wrap:wrap">
            <div class="chart" id="chart-sla" style="min-height:0;width:150px;flex:0 0 150px"></div>
            <div>
                <div class="kpi-value" style="font-size:34px"><?php echo $kpis['sla']['percent'] === null ? '—' : $kpis['sla']['percent'] . '%'; ?></div>
                <div class="faded"><?php echo sprintf(__('%d of %d tickets created in the timeframe met their target'), $kpis['sla']['met'], $kpis['sla']['total']); ?></div>
                <div class="faded" style="margin-top:6px"><?php echo sprintf(__('%d at-risk warnings in the last 7 days'), $kpis['at_risk']); ?></div>
            </div>
        </div>
    </div>
<?php } ?>

<?php if ($show('mywork')) { ?>
    <div class="card">
        <div class="card-head"><h3><?php echo __('My work'); ?></h3>
            <div class="card-tools"><a href="tickets.php?queue=6"><?php echo __('All mine'); ?> &rarr;</a></div></div>
<?php   if (!count($mywork)) { ?>
        <div class="chart-empty"><?php echo __('Nothing assigned to you — nice.'); ?></div>
<?php   } else { ?>
        <table class="list" cellspacing="0" cellpadding="0">
            <tbody>
<?php       foreach ($mywork as $t) { $p = SLAEscalator::progress($t); ?>
                <tr>
                    <td><a href="tickets.php?id=<?php echo $t->getId(); ?>"><b>#<?php echo $t->getNumber(); ?></b></a>
                        <div class="truncate" style="max-width:260px"><?php echo Format::htmlchars($t->getSubject()); ?></div></td>
                    <td style="text-align:right;white-space:nowrap">
<?php           if ($p['state'] != 'none') { ?>
                        <span class="sla-chip sla-<?php echo $p['state']; ?>"><?php echo Format::htmlchars($p['label']); ?></span>
<?php           } ?>
                        <div class="faded small"><?php echo Format::htmlchars((string) $t->getStatus()); ?></div>
                    </td>
                </tr>
<?php       } ?>
            </tbody>
        </table>
<?php   } ?>
    </div>
<?php } ?>

<?php if ($show('recent')) { ?>
    <div class="card">
        <div class="card-head"><h3><?php echo __('Recent activity'); ?></h3></div>
<?php   if (!$recent) { ?>
        <div class="chart-empty"><?php echo __('No recent activity in your departments'); ?></div>
<?php   } else { ?>
        <ul class="bar-list">
<?php       foreach ($recent as $item) { $e = $item['entry']; $t = $item['ticket'];
                $kinds = array('M' => __('Message'), 'R' => __('Reply'), 'N' => __('Note'));
                $poster = ($s = $e->getStaff()) ? $s->getName() : (($u = $e->getUser()) ? $u->getName() : $e->getPoster()); ?>
            <li style="grid-template-columns: 1fr auto">
                <span class="lbl"><span class="pill pill-muted"><?php echo $kinds[$e->getType()] ?? $e->getType(); ?></span>
                    <b><?php echo Format::htmlchars($poster); ?></b> <?php echo __('on'); ?>
                    <a href="tickets.php?id=<?php echo $t->getId(); ?>">#<?php echo $t->getNumber(); ?></a>
                    <span class="faded"><?php echo Format::htmlchars(Format::truncate($t->getSubject(), 48)); ?></span></span>
                <span class="num" title="<?php echo Format::datetime($e->created); ?>"><?php echo Format::relativeTime(Misc::db2gmtime($e->created)); ?></span>
            </li>
<?php       } ?>
        </ul>
<?php   } ?>
    </div>
<?php } ?>

<?php if ($show('health')) { ?>
    <div class="card wide">
        <div class="card-head"><h3><?php echo __('Integrations and incidents'); ?></h3>
            <div class="card-tools"><a href="incidents.php"><?php echo __('Incidents'); ?> &rarr;</a>
<?php   if ($thisstaff->isAdmin()) { ?> <a href="connectors.php"><?php echo __('Connectors'); ?> &rarr;</a><?php } ?></div></div>
        <div class="health-strip">
            <span class="health-item <?php echo $health['incidents']['open'] ? 'error' : 'ok'; ?>"><span class="dot"></span>
                <?php echo sprintf(__('%d open incidents'), $health['incidents']['open']); ?>
                <span class="faded">· <?php echo sprintf(__('%d critical'), $health['incidents']['by_severity']['critical']); ?></span></span>
<?php   foreach ($health['connectors'] as $c) { ?>
            <span class="health-item <?php echo !$c['active'] ? 'off' : ($c['last_error'] || $c['errors_24h'] ? 'error' : 'ok'); ?>" title="<?php echo Format::htmlchars($c['last_error'] ?: ''); ?>">
                <span class="dot"></span> <?php echo Format::htmlchars($c['name']); ?>
                <span class="faded">· <?php echo Format::htmlchars($c['type_name']); ?> · <?php echo sprintf(_N('%d channel', '%d channels', $c['channels']), $c['channels']); ?><?php
                    if ($c['errors_24h']) echo ' · ' . sprintf(__('%d errors'), $c['errors_24h']);
                    if ($c['pending']) echo ' · ' . sprintf(__('%d pending'), $c['pending']); ?></span>
            </span>
<?php   }
        if (!$health['connectors']) { ?>
            <span class="health-item off"><span class="dot"></span> <?php echo __('No connectors configured'); ?><?php if ($thisstaff->isAdmin()) echo ' · <a href="connectors.php?a=add">' . __('add one') . '</a>'; ?></span>
<?php   } ?>
        </div>
    </div>
<?php } ?>
</div>

<?php if ($show('stats')) { ?>
<div class="card wide" style="margin-bottom:16px">
<div class="card-head"><h3><?php echo __('Statistics'); ?> <i class="help-tip icon-question-sign" href="#statistics"></i></h3>
    <div class="card-tools"><span class="faded small"><?php echo __('Tickets organized by department, help topic and agent for the timeframe'); ?></span></div></div>
<form method="post" action="dashboard.php">
<?php echo csrf_token(); ?>
<input type="hidden" name="start" value="<?php echo Format::htmlchars($report->getStartDate()); ?>">
<input type="hidden" name="period" value="<?php echo Format::htmlchars($report->end); ?>">
<ul class="clean tabs">
<?php
$first = true;
$groups = $report->enumTabularGroups();
foreach ($groups as $g=>$desc) { ?>
    <li class="<?php echo $first ? 'active' : ''; ?>"><a href="#<?php echo Format::slugify($g); ?>"
        ><?php echo Format::htmlchars($desc); ?></a></li>
<?php
    $first = false;
} ?>
</ul>
<?php
$first = true;
foreach ($groups as $g=>$desc) {
    $data = $report->getTabularData($g); ?>
    <div class="tab_content <?php echo (!$first) ? 'hidden' : ''; ?>" id="<?php echo Format::slugify($g); ?>">
    <table class="list dashboard-stats" cellspacing="0" cellpadding="0"><thead><tr>
<?php
    $tips = array('Opened' => 'opened', 'Assigned' => 'assigned', 'Overdue' => 'overdue', 'Closed' => 'closed',
        'Reopened' => 'reopened', 'Deleted' => 'deleted', 'Service Time' => 'service_time', 'Response Time' => 'response_time');
    foreach ($data['columns'] as $j=>$c) { ?>
        <th <?php if ($j === 0) echo 'style="width:30%"'; else echo 'class="num"'; ?>><?php echo Format::htmlchars($c);
        if (isset($tips[$c])) echo ' <i class="help-tip icon-question-sign" href="#' . $tips[$c] . '"></i>'; ?></th>
<?php
    } ?>
    </tr></thead>
    <tbody>
<?php
    foreach ($data['data'] as $i=>$row) {
        echo '<tr>';
        foreach ($row as $j=>$td) {
            if ($j === 0) { ?>
                <td><b><?php echo Format::htmlchars($td); ?></b></td>
<?php       }
            else { ?>
                <td class="num" style="text-align:right;font-variant-numeric:tabular-nums"><?php echo Format::htmlchars($td); ?></td>
<?php       }
        }
        echo '</tr>';
    }
    if (!$data['data'])
        echo '<tr><td colspan="' . count($data['columns']) . '"><em class="faded">' . __('No data for this timeframe') . '</em></td></tr>';
    $first = false; ?>
    </tbody></table>
    <div style="margin-top: 8px"><button type="submit" class="action-button" name="export"
        value="<?php echo Format::htmlchars($g); ?>">
        <i class="icon-download"></i>
        <?php echo __('Export CSV'); ?></button>
        <i class="help-tip icon-question-sign" href="#export"></i></div>
    </div>
<?php
}
?>
</form>
</div>
<?php } ?>

<script type="text/javascript">
$(function() {
<?php if ($activity) { ?>
    OstCharts.render('#chart-activity', {
        type: <?php echo json_encode($chartType); ?>,
        labels: <?php echo json_encode($activity['labels']); ?>,
        series: <?php echo json_encode($activity['series']); ?>,
        emptyLabel: <?php echo json_encode(__('No ticket activity in this timeframe')); ?>
    });
<?php } ?>
<?php if ($breakdown) { ?>
    OstCharts.render('#chart-breakdown', {
        type: 'donut',
        size: 190,
        items: <?php echo json_encode($breakdown['items']); ?>,
        emptyLabel: <?php echo json_encode(__('No open tickets')); ?>
    });
<?php } ?>
<?php if ($show('sla') && $kpis) { ?>
    OstCharts.render('#chart-sla', {
        type: 'donut',
        size: 140,
        items: [
            {label: <?php echo json_encode(__('Met')); ?>, value: <?php echo (int) $kpis['sla']['met']; ?>},
            {label: <?php echo json_encode(__('Breached')); ?>, value: <?php echo (int) $kpis['sla']['breached']; ?>}
        ],
        emptyLabel: <?php echo json_encode(__('No tickets')); ?>
    });
    $('#chart-sla .chart-legend').remove();
<?php } ?>
    $('#dash-customize .widget-toggles label').on('click', function(e) {
        e.preventDefault();
        var $cb = $(this).find('input');
        $cb.prop('checked', !$cb.prop('checked'));
        $(this).toggleClass('on', $cb.prop('checked'));
    });
    $('#dash-customize form').on('click', function(e) { e.stopPropagation(); });
});
</script>
