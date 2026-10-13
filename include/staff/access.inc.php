<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin()) die('Access Denied');

$depts = Dept::objects()->order_by('name');
$deptList = array();
foreach ($depts as $d)
    $deptList[$d->getId()] = $d;
$filterDept = isset($_GET['dept']) ? (int) $_GET['dept'] : 0;
$roles = Role::getRoles();
$agents = Staff::objects()->order_by('lastname', 'firstname');

// Build access map: staff_id => dept_id => array(role, primary, alerts)
$access = array();
foreach ($agents as $a) {
    $access[$a->getId()] = array();
    if (($r = $a->getRole()))
        $access[$a->getId()][$a->getDeptId()] = array('role' => $r->getName(), 'primary' => true, 'alerts' => true);
    foreach ($a->dept_access as $da) {
        $role = $da->role ? $da->role->getName() : '';
        $access[$a->getId()][$da->dept_id] = array('role' => $role, 'primary' => false,
            'alerts' => $da->isAlertsEnabled());
    }
}
$visibleDepts = $filterDept && isset($deptList[$filterDept]) ? array($filterDept => $deptList[$filterDept]) : $deptList;
?>
<div class="sticky bar opaque">
    <div class="content">
        <div class="pull-left flush-left">
            <h2><?php echo __('Department Access'); ?> <i class="help-tip icon-question-sign" href="#access"></i></h2>
        </div>
        <div class="pull-right flush-right">
            <a href="staff.php?a=add" class="action-button"><i class="icon-user"></i> <?php echo __('Add Agent'); ?></a>
            <a href="departments.php?a=add" class="action-button"><i class="icon-sitemap"></i> <?php echo __('Add Department'); ?></a>
            <a href="roles.php?a=add" class="action-button"><i class="icon-key"></i> <?php echo __('Add Role'); ?></a>
        </div>
    </div>
</div>
<div class="clear"></div>

<p class="faded"><?php echo __('Every agent has a primary department (set on the agent profile) and may be granted extended access to other departments with a role that defines what they can do there. Use this view to see the whole picture and to grant or revoke access quickly.'); ?></p>

<form method="post" action="access.php" class="grant-bar">
    <?php csrf_token(); ?>
    <input type="hidden" name="do" value="grant">
    <strong><?php echo __('Grant access'); ?>:</strong>
    <select name="staff_id" required>
        <option value=""><?php echo __('Agent'); ?></option>
<?php foreach ($agents as $a) { ?>
        <option value="<?php echo $a->getId(); ?>"><?php echo Format::htmlchars($a->getName()); ?></option>
<?php } ?>
    </select>
    <select name="dept_id" required>
        <option value=""><?php echo __('Department'); ?></option>
<?php foreach ($deptList as $id => $d) { ?>
        <option value="<?php echo $id; ?>"><?php echo Format::htmlchars($d->getName()); ?></option>
<?php } ?>
    </select>
    <select name="role_id" required>
        <option value=""><?php echo __('Role'); ?></option>
<?php foreach ($roles as $id => $name) { ?>
        <option value="<?php echo $id; ?>"><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
    </select>
    <label class="inline checkbox"><input type="checkbox" name="alerts" value="1" checked> <?php echo __('Alerts'); ?></label>
    <button type="submit" class="button"><i class="icon-plus-sign"></i> <?php echo __('Grant'); ?></button>
</form>

<form method="get" action="access.php" class="filter-bar">
    <label><?php echo __('Show'); ?>:
    <select name="dept" onchange="this.form.submit()">
        <option value="0"><?php echo __('All departments'); ?></option>
<?php foreach ($deptList as $id => $d) { ?>
        <option value="<?php echo $id; ?>" <?php if ($filterDept == $id) echo 'selected'; ?>><?php echo Format::htmlchars($d->getName()); ?></option>
<?php } ?>
    </select></label>
</form>

<div class="access-matrix-wrap">
<table class="list access-matrix" cellspacing="1" cellpadding="2">
    <thead>
        <tr>
            <th class="agent-col"><?php echo __('Agent'); ?></th>
<?php foreach ($visibleDepts as $id => $d) { ?>
            <th><a href="departments.php?id=<?php echo $id; ?>"><?php echo Format::htmlchars($d->getName()); ?></a>
                <?php if (($m = $d->getManager())) echo sprintf('<div class="faded small">%s</div>', Format::htmlchars($m->getName())); ?></th>
<?php } ?>
        </tr>
    </thead>
    <tbody>
<?php foreach ($agents as $a) { ?>
        <tr class="<?php echo $a->isActive() ? '' : 'faded'; ?>">
            <td class="agent-col">
                <a href="staff.php?id=<?php echo $a->getId(); ?>"><?php echo Format::htmlchars($a->getName()); ?></a>
                <div class="faded small"><?php echo $a->isAdmin() ? __('Administrator') : __('Agent'); ?><?php if (!$a->isActive()) echo ' · ' . __('Locked'); ?></div>
            </td>
<?php   foreach ($visibleDepts as $id => $d) {
            $cell = isset($access[$a->getId()][$id]) ? $access[$a->getId()][$id] : null; ?>
            <td class="access-cell <?php echo $cell ? ($cell['primary'] ? 'primary' : 'extended') : 'none'; ?>">
<?php       if ($cell) { ?>
                <span class="role-name"><?php echo Format::htmlchars($cell['role']); ?></span>
                <span class="faded small"><?php echo $cell['primary'] ? __('primary') : __('extended'); ?><?php if (!$cell['alerts']) echo ' · ' . __('no alerts'); ?></span>
<?php           if (!$cell['primary']) { ?>
                <form method="post" action="access.php" class="inline-revoke">
                    <?php csrf_token(); ?>
                    <input type="hidden" name="do" value="revoke">
                    <input type="hidden" name="staff_id" value="<?php echo $a->getId(); ?>">
                    <input type="hidden" name="dept_id" value="<?php echo $id; ?>">
                    <button type="submit" class="link" title="<?php echo __('Revoke'); ?>"><i class="icon-remove"></i></button>
                </form>
<?php           }
            } else { ?>
                <span class="faded">&mdash;</span>
<?php       } ?>
            </td>
<?php   } ?>
        </tr>
<?php } ?>
    </tbody>
</table>
</div>
