<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin()) die('Access Denied');
$info = $qs = array();
if($sla && $_REQUEST['a']!='add'){
    $title=__('Update SLA Plan' /* SLA is abbreviation for Service Level Agreement */);
    $action='update';
    $submit_text=__('Save Changes');
    $info=$sla->getInfo();
    $info['id']=$sla->getId();
    $trans['name'] = $sla->getTranslateTag('name');
    $qs += array('id' => $sla->getId());
}else {
    $title=__('Add New SLA Plan' /* SLA is abbreviation for Service Level Agreement */);
    $action='add';
    $submit_text=__('Add Plan');
    $info['isactive']=isset($info['isactive'])?$info['isactive']:1;
    $info['enable_priority_escalation']=isset($info['enable_priority_escalation'])?$info['enable_priority_escalation']:1;
    $info['disable_overdue_alerts']=isset($info['disable_overdue_alerts'])?$info['disable_overdue_alerts']:0;
    $info['warn_percent'] = 75;
    $qs += array('a' => $_REQUEST['a']);
}
$rules = array();
if ($errors && $_POST) {
    $rules = SLA::parseEscalationRules($_POST, $_e = array());
} elseif (isset($info['escalation_rules']) && is_array($info['escalation_rules'])) {
    $rules = $info['escalation_rules'];
}
$info=Format::htmlchars(($errors && $_POST)?$_POST:$info, true);

$priorities = Priority::getPriorities();
$depts = Dept::getDepartments();
$agents = Staff::getStaffMembers();
$teams = Team::getTeams();
$notifyOptions = array(
    'assignee' => __('Assigned agent / team'),
    'manager' => __('Department manager'),
    'dept' => __('Department members'),
    'channels' => __('Connected channels (Linear / Teams / webhooks)'),
);
?>
<form action="slas.php?<?php echo Http::build_query($qs); ?>" method="post" class="save">
 <?php csrf_token(); ?>
 <input type="hidden" name="do" value="<?php echo $action; ?>">
 <input type="hidden" name="a" value="<?php echo Format::htmlchars($_REQUEST['a']); ?>">
 <input type="hidden" name="id" value="<?php echo $info['id']; ?>">
 <h2><?php echo $title; ?>
    <?php if (isset($info['name'])) { ?><small>
    — <?php echo $info['name']; ?></small>
     <?php } ?>
</h2>
<ul class="clean tabs">
    <li class="active"><a href="#sla-general"><i class="icon-time"></i> <?php echo __('Targets'); ?></a></li>
    <li><a href="#sla-escalation"><i class="icon-level-up"></i> <?php echo __('Escalation'); ?>
        <?php if ($rules) echo sprintf(' (%d)', count($rules)); ?></a></li>
</ul>
<div id="sla-general" class="tab_content">
 <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr>
            <th colspan="2">
                <em><?php echo __('Tickets are marked overdue on grace period violation.');?></em>
            </th>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td width="180" class="required">
              <?php echo __('Name');?>:
            </td>
            <td>
                <input type="text" size="30" name="name" value="<?php echo $info['name']; ?>"
                    autofocus data-translate-tag="<?php echo $trans['name']; ?>"/>
                &nbsp;<span class="error">*&nbsp;<?php echo $errors['name']; ?></span>&nbsp;<i class="help-tip icon-question-sign" href="#name"></i>
            </td>
        </tr>
        <tr>
            <td width="180" class="required">
                <?php echo __('Status');?>:
            </td>
            <td>
                <input type="radio" name="isactive" value="1" <?php echo $info['isactive']?'checked="checked"':''; ?>><strong><?php echo __('Active');?></strong>
                <input type="radio" name="isactive" value="0" <?php echo !$info['isactive']?'checked="checked"':''; ?>><?php echo __('Disabled');?>
                &nbsp;<span class="error">*&nbsp;<?php echo $errors['isactive']; ?></span>
            </td>
        </tr>
        <tr>
            <td width="180" class="required">
              <?php echo __('Grace Period');?>:
            </td>
            <td>
                <input type="text" size="10" name="grace_period" value="<?php echo $info['grace_period']; ?>">
                <em>( <?php echo __('in hours');?> )</em>
                &nbsp;<span class="error">*&nbsp;<?php echo $errors['grace_period']; ?></span>&nbsp;<i class="help-tip icon-question-sign" href="#grace_period"></i>
            </td>
        </tr>
        <tr>
            <td width="180">
              <?php echo __('First Response Target');?>:
            </td>
            <td>
                <input type="text" size="10" name="response_period" value="<?php echo $info['response_period']; ?>">
                <em>( <?php echo __('in hours, 0 to disable');?> )</em>
                &nbsp;<span class="error"><?php echo $errors['response_period']; ?></span>&nbsp;<i class="help-tip icon-question-sign" href="#response_period"></i>
            </td>
        </tr>
        <tr>
            <td width="180">
              <?php echo __('At-Risk Warning');?>:
            </td>
            <td>
                <?php echo __('after'); ?>
                <input type="text" size="4" name="warn_percent" value="<?php echo $info['warn_percent']; ?>">
                <em>% <?php echo __('of the grace period has elapsed (0 to disable)');?></em>
                &nbsp;<span class="error"><?php echo $errors['warn_percent']; ?></span>&nbsp;<i class="help-tip icon-question-sign" href="#warn_percent"></i>
            </td>
        </tr>
        <tr>
            <td width="180">
                <?php echo __('Schedule');?>:
            </td>
            <td>
                <select name="schedule_id">
                    <option value="0" selected="selected" >&mdash; <?php
                    echo __('System Default');?> &mdash;</option>
                    <?php
                    if ($schedules=BusinessHoursSchedule::getSchedules()) {
                        foreach ($schedules as $s) {
                            echo sprintf('<option value="%d" %s>%s</option>',
                                    $s->getId(), ($info['schedule_id']==$s->getId()) ? 'selected="selected"' : '', $s->getName());
                        }
                    }
                    ?>
                </select>
                &nbsp;<font class="error">&nbsp;<?php echo $errors['schedule_id']; ?></font>
                <i class="help-tip icon-question-sign"
                href="#schedule"></i>
            </td>
        </tr>
        <tr>
            <td width="180">
                <?php echo __('Pause While Waiting'); ?>:
            </td>
            <td>
                <input type="checkbox" name="pause_answered" value="1" <?php echo $info['pause_answered']?'checked="checked"':''; ?> >
                <?php echo __('Pause the clock while the ticket is answered and waiting on the requester'); ?>
                &nbsp;<i class="help-tip icon-question-sign" href="#pause_answered"></i>
            </td>
        </tr>
        <tr>
            <td width="180">
                <?php echo __('Transient'); ?>:
            </td>
            <td>
                <input type="checkbox" name="transient" value="1" <?php echo $info['transient']?'checked="checked"':''; ?> >
                <?php echo __('SLA can be overridden on ticket transfer or help topic change'); ?>
                &nbsp;<i class="help-tip icon-question-sign" href="#transient"></i>
            </td>
        </tr>
        <tr>
            <td width="180">
                <?php echo __('Ticket Overdue Alerts');?>:
            </td>
            <td>
                <input type="checkbox" name="disable_overdue_alerts" value="1" <?php echo $info['disable_overdue_alerts']?'checked="checked"':''; ?> >
                    <?php echo __('<strong>Disable</strong> overdue alerts notices.'); ?>
                    <em><?php echo __('(Override global setting)'); ?></em>
            </td>
        </tr>
        <tr>
            <th colspan="2">
                <em><strong><?php echo __('Internal Notes');?></strong>: <?php echo __("Be liberal, they're internal");?>
                </em>
            </th>
        </tr>
        <tr>
            <td colspan=2>
                <textarea class="richtext no-bar" name="notes" cols="21"
                    rows="8" style="width: 80%;"><?php echo Format::viewableImages($info['notes']); ?></textarea>
            </td>
        </tr>
    </tbody>
</table>
</div>

<div id="sla-escalation" class="hidden tab_content">
 <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr>
            <th colspan="2">
                <em><?php echo __('Escalation steps are applied automatically once a ticket is overdue. Each step runs once, the given number of hours after the due date (0 = immediately on breach).');?>
                <i class="help-tip icon-question-sign" href="#escalation"></i></em>
                <span class="error"><?php echo $errors['escalation']; ?></span>
            </th>
        </tr>
    </thead>
 </table>
 <table class="list escalation-steps" id="escalation-steps" width="940" cellspacing="1" cellpadding="2">
    <thead>
        <tr>
            <th width="90"><?php echo __('Hours after due'); ?></th>
            <th width="130"><?php echo __('Set priority'); ?></th>
            <th width="170"><?php echo __('Assign to'); ?></th>
            <th width="150"><?php echo __('Transfer to'); ?></th>
            <th><?php echo __('Notify'); ?></th>
            <th width="30"></th>
        </tr>
    </thead>
    <tbody>
<?php
$renderRow = function($i, $rule) use ($priorities, $depts, $agents, $teams, $notifyOptions) {
    $rule = $rule ?: array();
    $after = isset($rule['after']) ? $rule['after'] : '';
    $notify = isset($rule['notify']) && is_array($rule['notify']) ? $rule['notify'] : array();
    ?>
        <tr class="escalation-step">
            <td><input type="text" name="esc_after[<?php echo $i; ?>]" size="5" value="<?php echo Format::htmlchars($after); ?>" placeholder="0"></td>
            <td>
                <select name="esc_priority[<?php echo $i; ?>]">
                    <option value="0">&mdash; <?php echo __('Keep'); ?> &mdash;</option>
<?php foreach ($priorities as $id => $name) { ?>
                    <option value="<?php echo $id; ?>" <?php if (isset($rule['priority_id']) && $rule['priority_id'] == $id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                </select>
            </td>
            <td>
                <select name="esc_assignee[<?php echo $i; ?>]">
                    <option value="">&mdash; <?php echo __('Keep'); ?> &mdash;</option>
                    <option value="manager" <?php if (isset($rule['assignee']) && $rule['assignee'] == 'manager') echo 'selected'; ?>><?php echo __('Department manager'); ?></option>
                    <optgroup label="<?php echo __('Agents'); ?>">
<?php foreach ($agents as $id => $name) { ?>
                    <option value="s<?php echo $id; ?>" <?php if (isset($rule['assignee']) && $rule['assignee'] == 's'.$id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                    </optgroup>
                    <optgroup label="<?php echo __('Teams'); ?>">
<?php foreach ($teams as $id => $name) { ?>
                    <option value="t<?php echo $id; ?>" <?php if (isset($rule['assignee']) && $rule['assignee'] == 't'.$id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                    </optgroup>
                </select>
            </td>
            <td>
                <select name="esc_dept[<?php echo $i; ?>]">
                    <option value="0">&mdash; <?php echo __('Keep'); ?> &mdash;</option>
<?php foreach ($depts as $id => $name) { ?>
                    <option value="<?php echo $id; ?>" <?php if (isset($rule['dept_id']) && $rule['dept_id'] == $id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                </select>
            </td>
            <td>
<?php foreach ($notifyOptions as $k => $label) { ?>
                <label class="inline checkbox"><input type="checkbox" name="esc_notify[<?php echo $i; ?>][]" value="<?php echo $k; ?>" <?php if (in_array($k, $notify)) echo 'checked'; ?>> <?php echo $label; ?></label>
<?php } ?>
                <div><input type="text" name="esc_note[<?php echo $i; ?>]" placeholder="<?php echo __('Internal note added at this step (optional)'); ?>" value="<?php echo Format::htmlchars(isset($rule['note']) ? $rule['note'] : ''); ?>" style="width:95%"></div>
            </td>
            <td><a href="#" class="remove-step" title="<?php echo __('Remove'); ?>"><i class="icon-trash"></i></a></td>
        </tr>
<?php
};
$i = 0;
foreach ($rules as $rule)
    $renderRow($i++, $rule);
?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6">
                <a href="#" id="add-escalation-step" class="action-button"><i class="icon-plus-sign"></i> <?php echo __('Add step'); ?></a>
                <span class="faded" style="margin-left:10px"><?php echo __('Tip: step at 0 hours fires as soon as the ticket is marked overdue; add later steps (e.g. 4, 24) to keep escalating.'); ?></span>
            </td>
        </tr>
    </tfoot>
 </table>
 <table style="display:none"><tbody id="escalation-template"><?php $renderRow('__IDX__', array()); ?></tbody></table>
</div>
<p style="text-align:center;">
    <input type="submit" name="submit" value="<?php echo $submit_text; ?>">
    <input type="reset"  name="reset"  value="<?php echo __('Reset');?>">
    <input type="button" name="cancel" value="<?php echo __('Cancel');?>" onclick='window.location.href="slas.php"'>
</p>
</form>
<script type="text/javascript">
$(function() {
    var next = <?php echo (int) $i; ?>;
    $('#add-escalation-step').on('click', function(e) {
        e.preventDefault();
        var html = $('#escalation-template').html().replace(/__IDX__/g, next++);
        $('#escalation-steps tbody').append(html);
    });
    $(document).on('click', '#escalation-steps .remove-step', function(e) {
        e.preventDefault();
        $(this).closest('tr').remove();
    });
    if (!$('#escalation-steps tbody tr').length && <?php echo $rules ? 'false' : 'true'; ?> && location.hash === '#sla-escalation')
        $('#add-escalation-step').trigger('click');
});
</script>
