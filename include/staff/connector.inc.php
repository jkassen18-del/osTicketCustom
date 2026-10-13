<?php
if(!defined('OSTADMININC') || !$thisstaff || !$thisstaff->isAdmin()) die('Access Denied');

$types = ConnectorManager::getTypes();
$info = $qs = array();
$isNew = !$connector || !$connector->getId();

if ($isNew) {
    $type = $_REQUEST['type'] ?? ($connector ? $connector->getType() : '');
    if (!$type || !isset($types[$type])) {
        // Type chooser
        ?>
        <h2><?php echo __('Add Connector'); ?></h2>
        <p class="faded"><?php echo __('Choose the system you want to connect. Each connector can map any number of channels to departments.'); ?></p>
        <div class="type-cards large">
        <?php foreach ($types as $t => $name) { ?>
            <a class="type-card" href="connectors.php?a=add&type=<?php echo $t; ?>">
                <span class="type-card-title"><?php echo Format::htmlchars($name); ?></span>
                <span class="type-card-desc"><?php echo Format::htmlchars(ConnectorManager::getTypeDescription($t)); ?></span>
                <span class="type-card-cta"><?php echo __('Set up'); ?> &rarr;</span>
            </a>
        <?php } ?>
        </div>
        <?php
        return;
    }
    if (!$connector)
        $connector = Connector::create(array('type' => $type));
    $title = sprintf(__('Add %s Connector'), $types[$type]);
    $action = 'create';
    $submit_text = __('Create Connector');
    $info = array('isactive' => 1, 'inbound' => 1, 'outbound' => 1, 'alerts' => 1,
        'sync_status' => 1, 'push_notes' => 0, 'type' => $type);
    $qs += array('a' => 'add', 'type' => $type);
} else {
    $title = sprintf(__('%s Connector'), $connector->getTypeName());
    $action = 'update';
    $submit_text = __('Save Changes');
    $info = $connector->getInfo();
    $qs += array('id' => $connector->getId());
}

$backend = $connector->getBackend();
$fields = $backend->getConfigFields();
// Defaults for new connectors
if ($isNew)
    foreach ($fields as $name => $F)
        if (!isset($info[$name]) && isset($F['default']))
            $info[$name] = $F['default'];

$info = ($errors && $_POST) ? Format::htmlchars($_POST, true) + $info : Format::htmlchars($info, true);
$agents = Staff::getStaffMembers();
$topics = Topic::getHelpTopics(false, false);

$renderField = function($name, $F, $value, $errors) use ($agents, $topics) {
    $type = isset($F['type']) ? $F['type'] : 'text';
    $required = !empty($F['required']);
    ?>
        <tr>
            <td width="220" class="<?php echo $required ? 'required' : ''; ?>">
                <?php echo Format::htmlchars($F['label']); ?>:
            </td>
            <td>
<?php
    switch ($type) {
    case 'password':
        $has = is_string($value) && $value !== '';
        ?>
                <input type="password" size="40" name="<?php echo $name; ?>" value="" autocomplete="new-password"
                    placeholder="<?php echo $has ? __('(saved — leave blank to keep)') : ''; ?>">
<?php   break;
    case 'textarea': ?>
                <textarea name="<?php echo $name; ?>" rows="4" cols="60" style="width:70%"><?php echo is_scalar($value) ? $value : ''; ?></textarea>
<?php   break;
    case 'boolean': ?>
                <label class="checkbox inline">
                    <input type="checkbox" name="<?php echo $name; ?>" value="1" <?php echo $value ? 'checked="checked"' : ''; ?>>
                    <?php echo __('Enabled'); ?>
                </label>
<?php   break;
    case 'select': ?>
                <select name="<?php echo $name; ?>">
<?php   foreach ($F['choices'] as $k => $label) { ?>
                    <option value="<?php echo Format::htmlchars($k); ?>" <?php if ((string) $value === (string) $k) echo 'selected'; ?>><?php echo Format::htmlchars($label); ?></option>
<?php   } ?>
                </select>
<?php   break;
    case 'staff': ?>
                <select name="<?php echo $name; ?>">
                    <option value="0">&mdash; <?php echo __('None'); ?> &mdash;</option>
<?php   foreach ($agents as $id => $label) { ?>
                    <option value="<?php echo $id; ?>" <?php if ((int) $value === (int) $id) echo 'selected'; ?>><?php echo Format::htmlchars($label); ?></option>
<?php   } ?>
                </select>
<?php   break;
    case 'topic': ?>
                <select name="<?php echo $name; ?>">
                    <option value="0">&mdash; <?php echo __('Department default'); ?> &mdash;</option>
<?php   foreach ($topics as $id => $label) { ?>
                    <option value="<?php echo $id; ?>" <?php if ((int) $value === (int) $id) echo 'selected'; ?>><?php echo Format::htmlchars($label); ?></option>
<?php   } ?>
                </select>
<?php   break;
    case 'checklist':
        $selected = is_array($value) ? $value : (isset($F['default']) ? $F['default'] : array());
        foreach ($F['choices'] as $k => $label) { ?>
                <label class="checkbox inline" style="margin-right:12px">
                    <input type="checkbox" name="<?php echo $name; ?>[]" value="<?php echo Format::htmlchars($k); ?>" <?php if (in_array($k, $selected)) echo 'checked'; ?>>
                    <?php echo __($label); ?>
                </label>
<?php   }
        break;
    case 'number': ?>
                <input type="number" name="<?php echo $name; ?>" value="<?php echo is_scalar($value) ? $value : ''; ?>" style="width:120px">
<?php   break;
    default: ?>
                <input type="text" size="50" name="<?php echo $name; ?>" value="<?php echo is_scalar($value) ? $value : ''; ?>">
<?php
    }
    if (!empty($errors[$name]))
        echo sprintf('&nbsp;<span class="error">%s</span>', $errors[$name]);
    if (!empty($F['hint']))
        echo sprintf('<div class="faded field-hint">%s</div>', $F['hint']);
    ?>
            </td>
        </tr>
<?php
};
?>
<form action="connectors.php?<?php echo Http::build_query($qs); ?>" method="post" class="save" id="connector-form">
 <?php csrf_token(); ?>
 <input type="hidden" name="do" value="<?php echo $action; ?>">
 <input type="hidden" name="a" value="<?php echo Format::htmlchars($_REQUEST['a']); ?>">
 <input type="hidden" name="type" value="<?php echo Format::htmlchars($connector->getType()); ?>">
 <input type="hidden" name="id" value="<?php echo $connector->getId(); ?>">
 <div class="sticky bar">
    <div class="content">
        <div class="pull-left flush-left">
            <h2><?php echo $title; ?>
                <?php if (!$isNew) { ?><small>— <?php echo Format::htmlchars($connector->getName()); ?></small><?php } ?>
            </h2>
        </div>
        <div class="pull-right flush-right">
<?php if (!$isNew) { ?>
            <a href="connectors.php?id=<?php echo $connector->getId(); ?>&a=add-channel" class="action-button"><i class="icon-plus-sign"></i> <?php echo __('Add Channel'); ?></a>
            <button type="submit" class="action-button" name="do" value="test" formnovalidate><i class="icon-signal"></i> <?php echo __('Test Connection'); ?></button>
<?php } ?>
            <a href="connectors.php" class="action-button"><i class="icon-list"></i> <?php echo __('All Connectors'); ?></a>
        </div>
    </div>
 </div>
 <div class="clear"></div>

<ul class="clean tabs">
    <li class="active"><a href="#settings"><i class="icon-cog"></i> <?php echo __('Settings'); ?></a></li>
<?php if (!$isNew) { ?>
    <li><a href="#channels"><i class="icon-sitemap"></i> <?php echo __('Channels'); ?> (<?php echo $connector->getNumChannels(); ?>)</a></li>
    <li><a href="#activity"><i class="icon-list-alt"></i> <?php echo __('Activity'); ?></a></li>
<?php } ?>
    <li><a href="#setup"><i class="icon-book"></i> <?php echo __('Setup Guide'); ?></a></li>
</ul>

<div id="settings" class="tab_content">
 <table class="form_table" width="940" border="0" cellspacing="0" cellpadding="2">
    <thead>
        <tr><th colspan="2"><em><?php echo Format::htmlchars($backend::getDescription()); ?></em></th></tr>
    </thead>
    <tbody>
        <tr>
            <td width="220" class="required"><?php echo __('Name'); ?>:</td>
            <td>
                <input type="text" size="40" name="name" value="<?php echo $info['name'] ?? ''; ?>" autofocus>
                &nbsp;<span class="error">*&nbsp;<?php echo $errors['name'] ?? ''; ?></span>
                <div class="faded field-hint"><?php echo __('Shown to agents, e.g. "Engineering Linear" or "IT Teams"'); ?></div>
            </td>
        </tr>
        <tr>
            <td width="220" class="required"><?php echo __('Status'); ?>:</td>
            <td>
                <input type="radio" name="isactive" value="1" <?php echo !empty($info['isactive']) ? 'checked' : ''; ?>><strong><?php echo __('Active'); ?></strong>
                <input type="radio" name="isactive" value="0" <?php echo empty($info['isactive']) ? 'checked' : ''; ?>><?php echo __('Disabled'); ?>
            </td>
        </tr>
        <tr>
            <td width="220"><?php echo __('Directions'); ?>:</td>
            <td>
<?php if ($backend->supportsInbound()) { ?>
                <label class="checkbox inline"><input type="checkbox" name="inbound" value="1" <?php echo !empty($info['inbound']) ? 'checked' : ''; ?>> <?php echo __('Inbound — create tickets and post replies from this system'); ?></label><br>
<?php } ?>
<?php if ($backend->supportsOutbound()) { ?>
                <label class="checkbox inline"><input type="checkbox" name="outbound" value="1" <?php echo !empty($info['outbound']) ? 'checked' : ''; ?>> <?php echo __('Outbound — push new tickets, messages and replies'); ?></label><br>
                <label class="checkbox inline" style="margin-left:22px"><input type="checkbox" name="push_notes" value="1" <?php echo !empty($info['push_notes']) ? 'checked' : ''; ?>> <?php echo __('also push internal notes'); ?></label><br>
                <label class="checkbox inline" style="margin-left:22px"><input type="checkbox" name="sync_status" value="1" <?php echo !empty($info['sync_status']) ? 'checked' : ''; ?>> <?php echo __('mirror close / reopen both ways'); ?></label><br>
<?php } ?>
<?php if ($backend->supportsAlerts()) { ?>
                <label class="checkbox inline"><input type="checkbox" name="alerts" value="1" <?php echo !empty($info['alerts']) ? 'checked' : ''; ?>> <?php echo __('Alerts — SLA escalations and monitored incidents'); ?></label>
<?php } ?>
                <i class="help-tip icon-question-sign" href="#directions"></i>
            </td>
        </tr>
        <tr><th colspan="2"><em><strong><?php echo sprintf(__('%s settings'), $connector->getTypeName()); ?></strong></em></th></tr>
<?php
foreach ($fields as $name => $F)
    $renderField($name, $F, isset($info[$name]) ? $info[$name] : (isset($F['default']) ? $F['default'] : ''), $errors);
?>
<?php if (!$isNew) { ?>
        <tr><th colspan="2"><em><strong><?php echo __('Endpoint'); ?></strong></em></th></tr>
        <tr>
            <td width="220"><?php echo __('Webhook URL'); ?>:</td>
            <td><code class="copyable"><?php echo Format::htmlchars($connector->getWebhookUrl()); ?></code>
                <div class="faded field-hint"><?php echo __('Point the external system at this URL for inbound events (see the Setup Guide tab).'); ?></div></td>
        </tr>
<?php } ?>
        <tr><th colspan="2"><em><strong><?php echo __('Internal Notes'); ?></strong>: <?php echo __("Be liberal, they're internal"); ?></em></th></tr>
        <tr>
            <td colspan="2">
                <textarea class="richtext no-bar" name="notes" cols="21" rows="5" style="width: 60%;"><?php echo Format::viewableImages($info['notes'] ?? ''); ?></textarea>
            </td>
        </tr>
    </tbody>
 </table>
</div>

<?php if (!$isNew) { ?>
<div id="channels" class="hidden tab_content">
 <table class="list" border="0" cellspacing="1" cellpadding="2" width="940">
    <thead>
        <tr>
            <th width="22%"><?php echo __('Channel'); ?></th>
            <th width="26%"><?php echo Format::htmlchars($backend->getChannelIdLabel()); ?></th>
            <th width="18%"><?php echo __('Department'); ?></th>
            <th width="22%"><?php echo __('Directions'); ?></th>
            <th width="12%"><?php echo __('Status'); ?></th>
        </tr>
    </thead>
    <tbody>
<?php
    $channels = $connector->getChannels();
    if (!count($channels)) { ?>
        <tr><td colspan="5"><em><?php echo sprintf(__('No channels mapped yet &mdash; %s add the first one %s.'),
            '<a href="connectors.php?id='.$connector->getId().'&a=add-channel">', '</a>'); ?></em></td></tr>
<?php
    }
    foreach ($channels as $ch) { ?>
        <tr>
            <td><a href="connectors.php?id=<?php echo $connector->getId(); ?>&cid=<?php echo $ch->getId(); ?>"><?php echo Format::htmlchars($ch->getName()); ?></a></td>
            <td><code><?php echo Format::htmlchars(Format::truncate($ch->getChannelId(), 40)); ?></code></td>
            <td><?php echo Format::htmlchars($ch->getDeptName()); ?><?php if ($ch->isDefault() && $ch->getDeptId()) echo ' <span class="faded">(' . __('default') . ')</span>'; ?></td>
            <td><?php echo ($d = $ch->getDirections()) ? implode(' · ', $d) : '<span class="faded">&mdash;</span>'; ?></td>
            <td><?php echo $ch->isEnabled() ? '<span class="pill pill-ok">'.__('Enabled').'</span>' : '<span class="pill">'.__('Disabled').'</span>'; ?></td>
        </tr>
<?php } ?>
    </tbody>
    <tfoot>
        <tr><td colspan="5">
            <a href="connectors.php?id=<?php echo $connector->getId(); ?>&a=add-channel" class="action-button"><i class="icon-plus-sign"></i> <?php echo __('Add Channel'); ?></a>
            <span class="faded"><?php echo __('A channel flagged as default catches departments without their own mapping.'); ?></span>
        </td></tr>
    </tfoot>
 </table>
</div>

<div id="activity" class="hidden tab_content">
 <div style="margin-bottom:8px">
    <?php
    $pending = $connector->getPendingJobs();
    $failed = ConnectorJob::objects()->filter(array('connector_id' => $connector->getId(),
        'status' => ConnectorJob::STATUS_FAILED))->count();
    echo sprintf('<span class="pill">%s</span> <span class="pill">%s</span>',
        sprintf(__('%d pending deliveries'), $pending), sprintf(__('%d failed'), $failed));
    ?>
    <button type="submit" class="action-button" name="do" value="retry-jobs" formnovalidate><i class="icon-refresh"></i> <?php echo __('Run queue / retry failed'); ?></button>
    <button type="submit" class="action-button" name="do" value="clear-events" formnovalidate><i class="icon-trash"></i> <?php echo __('Clear log'); ?></button>
 </div>
 <table class="list" border="0" cellspacing="1" cellpadding="2" width="940">
    <thead>
        <tr>
            <th width="15%"><?php echo __('When'); ?></th>
            <th width="6%"><?php echo __('Dir'); ?></th>
            <th width="16%"><?php echo __('Event'); ?></th>
            <th width="8%"><?php echo __('Status'); ?></th>
            <th width="12%"><?php echo __('Object'); ?></th>
            <th><?php echo __('Detail'); ?></th>
        </tr>
    </thead>
    <tbody>
<?php
    $events = $connector->getEvents(60);
    if (!count($events)) { ?>
        <tr><td colspan="6"><em><?php echo __('No activity recorded yet.'); ?></em></td></tr>
<?php
    }
    foreach ($events as $e) {
        $obj = '';
        if ($e->getObjectType() == 'T' && ($t = Ticket::lookup((int) $e->getObjectId())))
            $obj = sprintf('<a href="tickets.php?id=%d">#%s</a>', $t->getId(), $t->getNumber());
        elseif ($e->getObjectType() == 'E')
            $obj = sprintf('%s %d', __('Entry'), $e->getObjectId());
        elseif ($e->getObjectType() == 'I')
            $obj = sprintf('<a href="incidents.php?id=%d">%s %d</a>', $e->getObjectId(), __('Incident'), $e->getObjectId());
        ?>
        <tr>
            <td><?php echo Format::datetime($e->getCreateDate()); ?></td>
            <td><?php echo $e->getDirection() == 'in' ? __('In') : __('Out'); ?></td>
            <td><?php echo Format::htmlchars($e->getEvent()); ?></td>
            <td><span class="pill pill-<?php echo $e->getStatus() == 'ok' ? 'ok' : ($e->getStatus() == 'error' ? 'danger' : 'muted'); ?>"><?php echo Format::htmlchars($e->getStatus()); ?></span></td>
            <td><?php echo $obj; ?></td>
            <td><span class="truncate" style="max-width:380px" title="<?php echo Format::htmlchars($e->getDetail()); ?>"><?php echo Format::htmlchars(Format::truncate((string) $e->getDetail(), 140)); ?></span></td>
        </tr>
<?php } ?>
    </tbody>
 </table>
</div>
<?php } ?>

<div id="setup" class="hidden tab_content">
    <div class="setup-guide">
        <?php echo $backend->getSetupInstructions(); ?>
        <p class="faded"><?php echo __('Need the full walkthrough? See docs/INTEGRATIONS.md in the installation folder.'); ?></p>
    </div>
</div>

<p style="text-align:center">
    <input type="submit" name="submit" value="<?php echo $submit_text; ?>">
    <input type="reset" name="reset" value="<?php echo __('Reset'); ?>">
    <input type="button" name="cancel" value="<?php echo __('Cancel'); ?>" onclick='window.location.href="connectors.php"'>
</p>
</form>
<script type="text/javascript">
$(function() {
    // Keep the "do" hidden field intact for the main submit; secondary
    // buttons override it through their own value attribute.
    $('#connector-form button[name=do]').on('click', function() {
        $('#connector-form input[name=do]').remove();
    });
});
</script>
