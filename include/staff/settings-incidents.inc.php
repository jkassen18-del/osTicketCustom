<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin() || !$config) die('Access Denied');
require_once INCLUDE_DIR.'class.incident.php';
$depts = Dept::getDepartments();
$topics = Topic::getHelpTopics(false, false);
$minSeverity = $config['incident_ticket_min_severity'] ?: 'warning';
?>
<h2><?php echo __('Monitoring and Incidents'); ?></h2>
<form action="settings.php?t=incidents" method="post" class="save">
<?php csrf_token(); ?>
<input type="hidden" name="t" value="incidents" >
<table class="form_table settings_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr><th colspan="2"><em><?php echo sprintf(__('External systems report outages and errors to %s with an API key that has the "Can Report Incidents" scope. Incidents are de-duplicated by system and key, turned into tickets and pushed to the alert channels of the department.'),
            '<code>POST ' . Format::htmlchars(rtrim($cfg->getBaseUrl(), '/')) . '/api/monitor/events.json</code>'); ?>
            <i class="help-tip icon-question-sign" href="#monitoring"></i></em></th></tr>
    </thead>
    <tbody>
        <tr>
            <td width="220"><?php echo __('Open tickets for incidents'); ?>:</td>
            <td>
                <label class="checkbox inline"><input type="checkbox" name="incident_create_ticket" value="1" <?php echo ($config['incident_create_ticket'] === null || $config['incident_create_ticket'] === '' || $config['incident_create_ticket']) ? 'checked' : ''; ?>> <?php echo __('Create a ticket when a new incident is reported'); ?></label>
                <div style="margin-top:4px"><?php echo __('only for severity'); ?>
                <select name="incident_ticket_min_severity">
<?php foreach (Incident::$severities as $k => $label) { ?>
                    <option value="<?php echo $k; ?>" <?php if ($minSeverity == $k) echo 'selected'; ?>><?php echo __($label); ?></option>
<?php } ?>
                </select> <?php echo __('and above'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Close tickets on recovery'); ?>:</td>
            <td>
                <label class="checkbox inline"><input type="checkbox" name="incident_close_ticket" value="1" <?php echo ($config['incident_close_ticket'] === null || $config['incident_close_ticket'] === '' || $config['incident_close_ticket']) ? 'checked' : ''; ?>> <?php echo __('Close the linked ticket when the incident is resolved'); ?></label>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Default department'); ?>:</td>
            <td>
                <select name="incident_dept_id">
                    <option value="0">&mdash; <?php echo __('System default department'); ?> &mdash;</option>
<?php foreach ($depts as $id => $name) { ?>
                    <option value="<?php echo $id; ?>" <?php if ((int) $config['incident_dept_id'] === (int) $id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                </select>
                <div class="faded field-hint"><?php echo __('Used when the event does not name a department. Events can target any department by name or id.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Help topic'); ?>:</td>
            <td>
                <select name="incident_topic_id">
                    <option value="0">&mdash; <?php echo __('Department default'); ?> &mdash;</option>
<?php foreach ($topics as $id => $name) { ?>
                    <option value="<?php echo $id; ?>" <?php if ((int) $config['incident_topic_id'] === (int) $id) echo 'selected'; ?>><?php echo Format::htmlchars($name); ?></option>
<?php } ?>
                </select>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Ticket requester email'); ?>:</td>
            <td>
                <input type="text" size="40" name="incident_reporter_email" value="<?php echo Format::htmlchars($config['incident_reporter_email']); ?>" placeholder="<?php echo Format::htmlchars($cfg->getDefaultEmailAddress()); ?>">
                <span class="error"><?php echo $errors['incident_reporter_email']; ?></span>
                <div class="faded field-hint"><?php echo __('Owner of incident tickets. Prefer a mailbox that is not fetched by this help desk.'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Re-notify channels'); ?>:</td>
            <td>
                <?php echo __('every'); ?> <input type="text" size="4" name="incident_renotify_minutes" value="<?php echo Format::htmlchars($config['incident_renotify_minutes'] !== '' && $config['incident_renotify_minutes'] !== null ? $config['incident_renotify_minutes'] : 60); ?>"> <?php echo __('minutes while an incident keeps firing (0 = only on severity changes)'); ?>
                <span class="error"><?php echo $errors['incident_renotify_minutes']; ?></span>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Retention'); ?>:</td>
            <td>
                <?php echo __('Delete resolved incidents after'); ?> <input type="text" size="4" name="incident_retention_days" value="<?php echo Format::htmlchars($config['incident_retention_days'] !== '' && $config['incident_retention_days'] !== null ? $config['incident_retention_days'] : 90); ?>"> <?php echo __('days (0 = keep forever)'); ?>
                <span class="error"><?php echo $errors['incident_retention_days']; ?></span>
            </td>
        </tr>
    </tbody>
</table>
<p style="text-align:center;">
    <input class="button" type="submit" name="submit-button" value="<?php echo __('Save Changes'); ?>">
    <input class="button" type="reset" name="reset" value="<?php echo __('Reset Changes'); ?>">
</p>
</form>
