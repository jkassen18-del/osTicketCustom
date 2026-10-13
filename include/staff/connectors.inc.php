<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin()) die('Access Denied');

$connectors = Connector::objects()->order_by('name');
$count = $connectors->count();
$page = ($_GET['p'] && is_numeric($_GET['p'])) ? $_GET['p'] : 1;
$pageNav = new Pagenate($count, $page, PAGE_LIMIT);
$pageNav->setURL('connectors.php');
$showing = $pageNav->showing().' '._N('connector', 'connectors', $count);
$pending = ConnectorJob::countPending();
$types = ConnectorManager::getTypes();
?>
<div class="sticky bar opaque">
    <div class="content">
        <div class="pull-left flush-left">
            <h2><?php echo __('Connectors'); ?>
                <i class="help-tip icon-question-sign" href="#connectors"></i></h2>
        </div>
        <div class="pull-right flush-right">
            <a href="connectors.php?a=add" class="green button action-button"><i class="icon-plus-sign"></i> <?php echo __('Add Connector'); ?></a>
            <span class="action-button" data-dropdown="#action-dropdown-more">
                <i class="icon-caret-down pull-right"></i>
                <span ><i class="icon-cog"></i> <?php echo __('More');?></span>
            </span>
            <div id="action-dropdown-more" class="action-dropdown anchor-right">
                <ul id="actions">
                    <li><a class="confirm" data-name="enable" href="connectors.php?a=enable">
                        <i class="icon-ok-sign icon-fixed-width"></i> <?php echo __('Enable'); ?></a></li>
                    <li><a class="confirm" data-name="disable" href="connectors.php?a=disable">
                        <i class="icon-ban-circle icon-fixed-width"></i> <?php echo __('Disable'); ?></a></li>
                    <li class="danger"><a class="confirm" data-name="delete" href="connectors.php?a=delete">
                        <i class="icon-trash icon-fixed-width"></i> <?php echo __('Delete'); ?></a></li>
                </ul>
            </div>
        </div>
    </div>
</div>
<div class="clear"></div>

<p class="faded" style="margin:4px 0 12px">
<?php echo __('Connectors link departments to the places where work actually happens. Map a Linear team, a Teams channel or a webhook routing key to a department: tickets created there land in the department, replies and status changes flow back, and SLA / incident alerts reach the right people.'); ?>
<?php if ($pending) echo sprintf(' <b>%s</b>', sprintf(_N('%d delivery pending', '%d deliveries pending', $pending), $pending)); ?>
</p>

<form action="connectors.php" method="POST" name="connectors">
<?php csrf_token(); ?>
<input type="hidden" name="do" value="mass_process" >
<input type="hidden" id="action" name="a" value="" >
<table class="list" border="0" cellspacing="1" cellpadding="0" width="940">
    <thead>
        <tr>
            <th width="4%">&nbsp;</th>
            <th width="26%"><?php echo __('Name'); ?></th>
            <th width="14%"><?php echo __('Type'); ?></th>
            <th width="10%"><?php echo __('Status'); ?></th>
            <th width="18%"><?php echo __('Directions'); ?></th>
            <th width="8%"><?php echo __('Channels'); ?></th>
            <th width="20%"><?php echo __('Last activity'); ?></th>
        </tr>
    </thead>
    <tbody>
<?php
if ($count) {
    foreach ($connectors->limit($pageNav->getLimit())->offset($pageNav->getStart()) as $c) {
        $id = $c->getId();
        $sel = ($errors && is_array($_POST['ids']) && in_array($id, $_POST['ids']));
        $dirs = array();
        if ($c->acceptsInbound()) $dirs[] = __('Inbound');
        if ($c->pushesOutbound()) $dirs[] = __('Outbound');
        if ($c->receivesAlerts()) $dirs[] = __('Alerts');
        $errs = $c->getNumErrors(24);
        ?>
        <tr>
            <td align="center"><input type="checkbox" class="ckb" name="ids[]" value="<?php echo $id; ?>" <?php echo $sel?'checked="checked"':''; ?>></td>
            <td><a href="connectors.php?id=<?php echo $id; ?>"><?php echo Format::htmlchars($c->getName()); ?></a>
                <?php if ($c->getLastError()) echo sprintf('&nbsp;<span class="label label-danger" title="%s">%s</span>',
                    Format::htmlchars(Format::truncate($c->getLastError(), 120)), __('Error')); ?></td>
            <td><?php echo Format::htmlchars($c->getTypeName()); ?></td>
            <td><?php echo $c->isActive() ? '<span class="pill pill-ok">'.__('Active').'</span>' : '<span class="pill">'.__('Disabled').'</span>'; ?></td>
            <td><?php echo $dirs ? implode(' · ', $dirs) : '<span class="faded">&mdash;</span>'; ?></td>
            <td><?php echo $c->getNumChannels(); ?></td>
            <td><?php
                echo $c->getLastRun() ? Format::datetime($c->getLastRun()) : '<span class="faded">'.__('Never').'</span>';
                if ($errs) echo sprintf(' <span class="faded">(%s)</span>', sprintf(_N('%d error in 24h', '%d errors in 24h', $errs), $errs));
            ?></td>
        </tr>
<?php
    }
} ?>
    </tbody>
    <tfoot>
     <tr>
        <td colspan="7">
            <?php if ($count) { ?>
            <?php echo __('Select'); ?>:&nbsp;
            <a id="selectAll" href="#ckb"><?php echo __('All'); ?></a>&nbsp;&nbsp;
            <a id="selectNone" href="#ckb"><?php echo __('None'); ?></a>&nbsp;&nbsp;
            <a id="selectToggle" href="#ckb"><?php echo __('Toggle'); ?></a>&nbsp;&nbsp;
            <?php } else {
                echo sprintf(__('No connectors yet &mdash; %s add one %s!'), '<a href="?a=add">', '</a>');
            } ?>
        </td>
     </tr>
    </tfoot>
</table>
<?php
if ($count)
    echo '<div>&nbsp;'.__('Page').':'.$pageNav->getPageLinks().'&nbsp;</div>';
?>
</form>

<div class="type-cards">
<?php foreach ($types as $type => $name) { ?>
    <a class="type-card" href="connectors.php?a=add&type=<?php echo $type; ?>">
        <span class="type-card-title"><?php echo Format::htmlchars($name); ?></span>
        <span class="type-card-desc"><?php echo Format::htmlchars(ConnectorManager::getTypeDescription($type)); ?></span>
    </a>
<?php } ?>
</div>

<div style="display:none;" class="dialog" id="confirm-action">
    <h3><?php echo __('Please Confirm'); ?></h3>
    <a class="close" href="">&times;</a>
    <hr/>
    <p class="confirm-action" style="display:none;" id="enable-confirm">
        <?php echo sprintf(__('Are you sure you want to <b>enable</b> %s?'), _N('selected connector', 'selected connectors', 2)); ?>
    </p>
    <p class="confirm-action" style="display:none;" id="disable-confirm">
        <?php echo sprintf(__('Are you sure you want to <b>disable</b> %s?'), _N('selected connector', 'selected connectors', 2)); ?>
    </p>
    <p class="confirm-action" style="display:none;" id="delete-confirm">
        <font color="red"><strong><?php echo sprintf(__('Are you sure you want to DELETE %s?'), _N('selected connector', 'selected connectors', 2)); ?></strong></font>
        <br><br><?php echo __('Channel mappings, links and the activity log of deleted connectors CANNOT be recovered.'); ?>
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
