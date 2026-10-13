<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin() || !$connector) die('Access Denied');

$backend = $connector->getBackend();
$qs = array('id' => $connector->getId());
if ($channel && $channel->getId()) {
    $title = __('Update Channel');
    $action = 'update-channel';
    $submit_text = __('Save Changes');
    $info = $channel->getInfo();
    $qs['cid'] = $channel->getId();
} else {
    $title = __('Add Channel');
    $action = 'add-channel';
    $submit_text = __('Add Channel');
    $info = array('inbound' => 1, 'outbound' => 1, 'alerts' => 1);
    $qs['a'] = 'add-channel';
}
$info = ($errors && $_POST) ? Format::htmlchars($_POST, true) + $info : Format::htmlchars($info, true);
$depts = Dept::getDepartments();
$topics = Topic::getHelpTopics(false, false);
$extraFields = $backend->getChannelFields();
?>
<form action="connectors.php?<?php echo Http::build_query($qs); ?>" method="post" class="save">
 <?php csrf_token(); ?>
 <input type="hidden" name="do" value="<?php echo $action; ?>">
 <input type="hidden" name="id" value="<?php echo $connector->getId(); ?>">
 <input type="hidden" name="cid" value="<?php echo $channel ? $channel->getId() : ''; ?>">
 <h2><?php echo $title; ?> <small>— <?php echo Format::htmlchars($connector->getName()); ?> (<?php echo Format::htmlchars($connector->getTypeName()); ?>)</small></h2>
 <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr><th colspan="2"><em><?php echo __('A channel routes between one place in the external system and one department.'); ?> <i class="help-tip icon-question-sign" href="#channel"></i></em></th></tr>
    </thead>
    <tbody>
        <tr>
            <td width="220" class="<?php echo $backend->allowsEmptyChannelId($info) ? '' : 'required'; ?>"><?php echo Format::htmlchars($backend->getChannelIdLabel()); ?>:</td>
            <td>
                <input type="text" size="50" name="channel_id" value="<?php echo $info['channel_id'] ?? ''; ?>" autofocus>
                &nbsp;<span class="error"><?php echo $errors['channel_id'] ?? ''; ?></span>
                <?php if (($hint = $backend->getChannelIdHint())) echo sprintf('<div class="faded field-hint">%s</div>', Format::htmlchars($hint)); ?>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Display name'); ?>:</td>
            <td><input type="text" size="40" name="name" value="<?php echo $info['name'] ?? ''; ?>" placeholder="<?php echo __('e.g. #it-helpdesk or Engineering (ENG)'); ?>"></td>
        </tr>
        <tr>
            <td width="220" class="required"><?php echo __('Department'); ?>:</td>
            <td>
                <select name="dept_id">
                    <option value="0">&mdash; <?php echo __('Select Department'); ?> &mdash;</option>
<?php foreach ($depts as $id => $name) { ?>
                    <option value="<?php echo $id; ?>" <?php if ((int) ($info['dept_id'] ?? 0) === (int) $id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                </select>
                &nbsp;<span class="error"><?php echo $errors['dept_id'] ?? ''; ?></span>
                <div class="faded field-hint"><?php echo __('Tickets from this channel are created in this department; activity of the department is pushed to this channel.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Directions'); ?>:</td>
            <td>
<?php if ($backend->supportsInbound()) { ?>
                <label class="checkbox inline"><input type="checkbox" name="inbound" value="1" <?php echo !empty($info['inbound']) ? 'checked' : ''; ?>> <?php echo __('Inbound (create tickets here)'); ?></label><br>
<?php } ?>
<?php if ($backend->supportsOutbound()) { ?>
                <label class="checkbox inline"><input type="checkbox" name="outbound" value="1" <?php echo !empty($info['outbound']) ? 'checked' : ''; ?>> <?php echo __('Outbound (push ticket activity)'); ?></label><br>
<?php } ?>
<?php if ($backend->supportsAlerts()) { ?>
                <label class="checkbox inline"><input type="checkbox" name="alerts" value="1" <?php echo !empty($info['alerts']) ? 'checked' : ''; ?>> <?php echo __('Alerts (SLA escalations, incidents)'); ?></label><br>
<?php } ?>
                <label class="checkbox inline"><input type="checkbox" name="is_default" value="1" <?php echo !empty($info['is_default']) ? 'checked' : ''; ?>> <?php echo __('Default channel for departments without a mapping'); ?></label><br>
                <label class="checkbox inline"><input type="checkbox" name="disabled" value="1" <?php echo !empty($info['disabled']) ? 'checked' : ''; ?>> <?php echo __('Disabled'); ?></label>
            </td>
        </tr>
<?php if ($extraFields) { ?>
        <tr><th colspan="2"><em><strong><?php echo sprintf(__('%s options'), $connector->getTypeName()); ?></strong></em></th></tr>
<?php
    foreach ($extraFields as $name => $F) {
        $type = $F['type'] ?? 'text';
        $value = $info[$name] ?? ($F['default'] ?? '');
        ?>
        <tr>
            <td width="220" class="<?php echo !empty($F['required']) ? 'required' : ''; ?>"><?php echo Format::htmlchars($F['label']); ?>:</td>
            <td>
<?php   if ($type == 'password') { ?>
                <input type="password" size="60" name="<?php echo $name; ?>" value="" autocomplete="new-password" placeholder="<?php echo $value !== '' ? __('(saved — leave blank to keep)') : ''; ?>">
<?php   } elseif ($type == 'topic') { ?>
                <select name="<?php echo $name; ?>">
                    <option value="0">&mdash; <?php echo __('Department default'); ?> &mdash;</option>
<?php       foreach ($topics as $id => $label) { ?>
                    <option value="<?php echo $id; ?>" <?php if ((int) $value === (int) $id) echo 'selected'; ?>><?php echo Format::htmlchars($label); ?></option>
<?php       } ?>
                </select>
<?php   } elseif ($type == 'boolean') { ?>
                <label class="checkbox inline"><input type="checkbox" name="<?php echo $name; ?>" value="1" <?php echo $value ? 'checked' : ''; ?>> <?php echo __('Enabled'); ?></label>
<?php   } else { ?>
                <input type="text" size="60" name="<?php echo $name; ?>" value="<?php echo is_scalar($value) ? $value : ''; ?>">
<?php   }
        if (!empty($errors[$name]))
            echo sprintf('&nbsp;<span class="error">%s</span>', $errors[$name]);
        if (!empty($F['hint']))
            echo sprintf('<div class="faded field-hint">%s</div>', Format::htmlchars($F['hint']));
        ?>
            </td>
        </tr>
<?php
    }
} ?>
    </tbody>
 </table>
<p style="text-align:center">
    <input type="submit" name="submit" value="<?php echo $submit_text; ?>">
    <input type="reset" name="reset" value="<?php echo __('Reset'); ?>">
    <input type="button" name="cancel" value="<?php echo __('Cancel'); ?>" onclick='window.location.href="connectors.php?id=<?php echo $connector->getId(); ?>#channels"'>
<?php if ($channel && $channel->getId()) { ?>
    <input type="submit" name="delete" class="red button" value="<?php echo __('Delete Channel'); ?>" onclick="this.form.elements['do'].value='delete-channel'; return confirm('<?php echo __('Delete this channel mapping?'); ?>');">
<?php } ?>
</p>
</form>
