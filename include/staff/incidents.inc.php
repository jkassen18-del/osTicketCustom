<?php
if(!defined('OSTSCPINC') || !$thisstaff) die('Access Denied');

$qs = array();
$status = isset($_REQUEST['status']) ? $_REQUEST['status'] : 'active';
$severity = isset($_REQUEST['severity']) ? $_REQUEST['severity'] : '';
$source = isset($_REQUEST['source']) ? trim($_REQUEST['source']) : '';
$query = isset($_REQUEST['q']) ? trim($_REQUEST['q']) : '';

$incidents = Incident::objects();
if (!$thisstaff->isAdmin())
    $incidents = $incidents->filter(Q::any(array(
        'dept_id__in' => $thisstaff->getDepts() ?: array(0),
        'dept_id' => 0,
    )));
switch ($status) {
case 'open':
    $incidents = $incidents->filter(array('status' => Incident::STATUS_OPEN)); break;
case 'acknowledged':
    $incidents = $incidents->filter(array('status' => Incident::STATUS_ACKNOWLEDGED)); break;
case 'resolved':
    $incidents = $incidents->filter(array('status' => Incident::STATUS_RESOLVED)); break;
case 'all':
    break;
default:
    $status = 'active';
    $incidents = $incidents->filter(array('status__in' => array(Incident::STATUS_OPEN, Incident::STATUS_ACKNOWLEDGED)));
}
$qs['status'] = $status;
if ($severity && isset(Incident::$severities[$severity])) {
    $incidents = $incidents->filter(array('severity' => $severity));
    $qs['severity'] = $severity;
}
if ($source) {
    $incidents = $incidents->filter(array('source__contains' => $source));
    $qs['source'] = $source;
}
if ($query) {
    $incidents = $incidents->filter(Q::any(array(
        'title__contains' => $query, 'detail__contains' => $query, 'external_id__contains' => $query)));
    $qs['q'] = $query;
}

$count = $incidents->count();
$page = ($_GET['p'] && is_numeric($_GET['p'])) ? $_GET['p'] : 1;
$pageNav = new Pagenate($count, $page, PAGE_LIMIT);
$pageNav->setURL('incidents.php', $qs);
$showing = $pageNav->showing().' '._N('incident', 'incidents', $count);
$counts = Incident::getCounts();
$sources = array();
foreach (Incident::objects()->values_flat('source')->distinct('source')->order_by('source') as $row)
    $sources[] = $row[0];
?>
<div class="sticky bar opaque">
    <div class="content">
        <div class="pull-left flush-left">
            <h2><?php echo __('Incidents'); ?> <i class="help-tip icon-question-sign" href="#incidents"></i></h2>
        </div>
        <div class="pull-right flush-right">
            <span class="action-button" data-dropdown="#action-dropdown-more">
                <i class="icon-caret-down pull-right"></i>
                <span ><i class="icon-cog"></i> <?php echo __('More');?></span>
            </span>
            <div id="action-dropdown-more" class="action-dropdown anchor-right">
                <ul id="actions">
                    <li><a class="confirm" data-name="acknowledge" href="incidents.php?a=acknowledge">
                        <i class="icon-eye-open icon-fixed-width"></i> <?php echo __('Acknowledge'); ?></a></li>
                    <li><a class="confirm" data-name="resolve" href="incidents.php?a=resolve">
                        <i class="icon-ok-sign icon-fixed-width"></i> <?php echo __('Resolve'); ?></a></li>
<?php if ($thisstaff->isAdmin()) { ?>
                    <li class="danger"><a class="confirm" data-name="delete" href="incidents.php?a=delete">
                        <i class="icon-trash icon-fixed-width"></i> <?php echo __('Delete'); ?></a></li>
<?php } ?>
                </ul>
            </div>
        </div>
    </div>
</div>
<div class="clear"></div>

<div class="kpi-row">
    <a class="kpi <?php if ($status == 'open') echo 'active'; ?>" href="incidents.php?status=open">
        <span class="kpi-value"><?php echo $counts['open']; ?></span>
        <span class="kpi-label"><?php echo __('Open'); ?></span>
    </a>
    <a class="kpi <?php if ($status == 'acknowledged') echo 'active'; ?>" href="incidents.php?status=acknowledged">
        <span class="kpi-value"><?php echo $counts['acknowledged']; ?></span>
        <span class="kpi-label"><?php echo __('Acknowledged'); ?></span>
    </a>
    <a class="kpi <?php if ($severity == 'critical') echo 'active'; ?>" href="incidents.php?status=active&severity=critical">
        <span class="kpi-value"><?php echo $counts['by_severity']['critical']; ?></span>
        <span class="kpi-label"><?php echo __('Critical'); ?></span>
    </a>
    <a class="kpi <?php if ($severity == 'error') echo 'active'; ?>" href="incidents.php?status=active&severity=error">
        <span class="kpi-value"><?php echo $counts['by_severity']['error']; ?></span>
        <span class="kpi-label"><?php echo __('Errors'); ?></span>
    </a>
    <a class="kpi <?php if ($status == 'resolved') echo 'active'; ?>" href="incidents.php?status=resolved">
        <span class="kpi-value"><?php echo $counts['resolved_24h']; ?></span>
        <span class="kpi-label"><?php echo __('Resolved (24h)'); ?></span>
    </a>
</div>

<form action="incidents.php" method="get" class="filter-bar">
    <select name="status">
<?php foreach (array('active' => __('Active'), 'open' => __('Open'), 'acknowledged' => __('Acknowledged'),
        'resolved' => __('Resolved'), 'all' => __('All')) as $k => $v) { ?>
        <option value="<?php echo $k; ?>" <?php if ($status == $k) echo 'selected'; ?>><?php echo $v; ?></option>
<?php } ?>
    </select>
    <select name="severity">
        <option value=""><?php echo __('Any severity'); ?></option>
<?php foreach (Incident::$severities as $k => $v) { ?>
        <option value="<?php echo $k; ?>" <?php if ($severity == $k) echo 'selected'; ?>><?php echo __($v); ?></option>
<?php } ?>
    </select>
    <select name="source">
        <option value=""><?php echo __('Any system'); ?></option>
<?php foreach ($sources as $s) { ?>
        <option value="<?php echo Format::htmlchars($s); ?>" <?php if ($source == $s) echo 'selected'; ?>><?php echo Format::htmlchars($s); ?></option>
<?php } ?>
    </select>
    <input type="text" name="q" value="<?php echo Format::htmlchars($query); ?>" placeholder="<?php echo __('Search title or key'); ?>">
    <button type="submit" class="button"><i class="icon-filter"></i> <?php echo __('Filter'); ?></button>
</form>

<form action="incidents.php" method="POST" name="incidents">
<?php csrf_token(); ?>
<input type="hidden" name="do" value="mass_process">
<input type="hidden" id="action" name="a" value="">
<table class="list incidents" border="0" cellspacing="1" cellpadding="0" width="940">
    <thead>
        <tr>
            <th width="4%">&nbsp;</th>
            <th width="9%"><?php echo __('Severity'); ?></th>
            <th width="35%"><?php echo __('Incident'); ?></th>
            <th width="13%"><?php echo __('System'); ?></th>
            <th width="12%"><?php echo __('Department'); ?></th>
            <th width="9%"><?php echo __('Ticket'); ?></th>
            <th width="18%"><?php echo __('Last seen'); ?></th>
        </tr>
    </thead>
    <tbody>
<?php
if ($count) {
    foreach ($incidents->order_by('-last_seen')->limit($pageNav->getLimit())->offset($pageNav->getStart()) as $i) {
        $ticket = $i->getTicket(); ?>
        <tr class="sev-<?php echo $i->getSeverity(); ?> <?php if ($i->isResolved()) echo 'resolved'; ?>">
            <td align="center"><input type="checkbox" class="ckb" name="ids[]" value="<?php echo $i->getId(); ?>"></td>
            <td><span class="pill pill-sev-<?php echo $i->getSeverity(); ?>"><?php echo $i->getSeverityLabel(); ?></span></td>
            <td>
                <a href="incidents.php?id=<?php echo $i->getId(); ?>"><?php echo Format::htmlchars($i->getTitle()); ?></a>
                <div class="faded truncate" style="max-width:330px"><?php echo Format::htmlchars(Format::truncate((string) $i->getDetail(), 110)); ?></div>
            </td>
            <td><?php echo Format::htmlchars($i->getSource()); ?></td>
            <td><?php echo Format::htmlchars($i->getDeptName()) ?: '<span class="faded">&mdash;</span>'; ?></td>
            <td><?php echo $ticket ? sprintf('<a href="tickets.php?id=%d">#%s</a>', $ticket->getId(), $ticket->getNumber()) : '<span class="faded">&mdash;</span>'; ?></td>
            <td><?php echo Format::datetime($i->getLastSeen()); ?>
                <?php if ($i->getOccurrences() > 1) echo sprintf('<span class="faded"> ×%d</span>', $i->getOccurrences()); ?>
                <?php if (!$i->isResolved()) echo sprintf('<div class="faded">%s</div>', $i->getStatusLabel()); ?></td>
        </tr>
<?php
    }
} ?>
    </tbody>
    <tfoot>
        <tr><td colspan="7">
<?php if ($count) { ?>
            <?php echo __('Select'); ?>:&nbsp;
            <a id="selectAll" href="#ckb"><?php echo __('All'); ?></a>&nbsp;&nbsp;
            <a id="selectNone" href="#ckb"><?php echo __('None'); ?></a>&nbsp;&nbsp;
            <a id="selectToggle" href="#ckb"><?php echo __('Toggle'); ?></a>&nbsp;&nbsp;
<?php } else { ?>
            <em><?php echo __('No incidents match the current filter.'); ?>
<?php   if ($thisstaff->isAdmin()) echo ' ' . sprintf(__('Systems report incidents with an API key that has the "Can Report Incidents" scope (%s Manage &rarr; API %s).'), '<a href="apikeys.php">', '</a>'); ?></em>
<?php } ?>
        </td></tr>
    </tfoot>
</table>
<?php if ($count) echo '<div>&nbsp;'.__('Page').':'.$pageNav->getPageLinks().'&nbsp;</div>'; ?>
</form>

<div style="display:none;" class="dialog" id="confirm-action">
    <h3><?php echo __('Please Confirm'); ?></h3>
    <a class="close" href="">&times;</a>
    <hr/>
    <p class="confirm-action" style="display:none;" id="acknowledge-confirm">
        <?php echo __('Acknowledge the selected incidents?'); ?>
    </p>
    <p class="confirm-action" style="display:none;" id="resolve-confirm">
        <?php echo __('Resolve the selected incidents? Linked tickets are closed when the incident settings say so.'); ?>
    </p>
    <p class="confirm-action" style="display:none;" id="delete-confirm">
        <font color="red"><strong><?php echo __('Are you sure you want to DELETE the selected incidents?'); ?></strong></font>
    </p>
    <div><?php echo __('Please confirm to continue.'); ?></div>
    <hr style="margin-top:1em"/>
    <p class="full-width">
        <span class="buttons pull-left">
            <input type="button" value="<?php echo __('No, Cancel'); ?>" class="close">
        </span>
        <span class="buttons pull-right">
            <input type="button" value="<?php echo __('Yes, Do it!'); ?>" class="confirm">
        </span>
     </p>
    <div class="clear"></div>
</div>
