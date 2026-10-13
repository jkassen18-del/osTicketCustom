<?php
// Calling convention:
// $ticket   - <Ticket> being adjusted
// $info     - dialog info (:title, :action, error)
// $progress - SLAEscalator::progress() result
// $events   - recent <SLAEvent> entries
global $cfg;
$sla = $ticket->getSLA();
$dueDate = $ticket->getEstDueDate();
$stateLabels = array(
    'ok' => __('On track'), 'warning' => __('At risk'), 'overdue' => __('Overdue'),
    'paused' => __('Paused'), 'closed' => __('Closed'), 'none' => __('No SLA target'),
);
?>
<h3 class="drag-handle"><?php echo $info[':title']; ?></h3>
<b><a class="close" href="#"><i class="icon-remove-circle"></i></a></b>
<div class="clear"></div>
<hr/>
<?php
if ($info['error']) {
    echo sprintf('<p id="msg_error">%s</p>', $info['error']);
} ?>
<div class="sla-summary" style="margin:0 5px 10px">
    <div class="sla-meter sla-<?php echo $progress['state']; ?>" title="<?php echo Format::htmlchars($progress['label']); ?>">
        <span style="width:<?php echo (int) $progress['percent']; ?>%"></span>
    </div>
    <div class="sla-summary-row">
        <span class="sla-pill sla-<?php echo $progress['state']; ?>"><?php
            echo $stateLabels[$progress['state']] ?? $progress['state']; ?></span>
        <span class="faded"><?php
            echo $sla ? sprintf(__('Plan: %s (%d h grace)'), Format::htmlchars($sla->getName()), $sla->getGracePeriod())
                : __('No SLA plan');
            if ($dueDate)
                echo ' · ' . sprintf(__('Due %s'), Format::datetime($dueDate));
            if ($progress['label'])
                echo ' · ' . Format::htmlchars($progress['label']);
            if ($ticket->getDueDate())
                echo ' · <em>' . __('manual due date') . '</em>';
        ?></span>
    </div>
</div>
<div style="display:block; margin:5px;">
<form method="post" name="sla-adjust" id="sla-adjust" action="<?php echo $info[':action']; ?>">
    <table width="100%" class="sla-adjust-options">
        <tbody>
            <tr>
                <td width="26"><input type="radio" name="mode" value="extend" id="sla-mode-extend" checked></td>
                <td>
                    <label for="sla-mode-extend"><strong><?php echo __('Extend'); ?></strong></label>
                    <div class="faded"><?php echo __('Push the due date out by a number of hours'); ?></div>
                    <div class="sla-adjust-field">
                        <input type="number" name="hours" min="1" max="8760" step="0.5" value="<?php echo Format::htmlchars($_POST['hours'] ?? '4'); ?>" style="width:90px">
                        <?php echo __('hours'); ?>
                        <span class="error"><?php echo $errors['hours'] ?? ''; ?></span>
                    </div>
                </td>
            </tr>
            <tr>
                <td><input type="radio" name="mode" value="set" id="sla-mode-set"></td>
                <td>
                    <label for="sla-mode-set"><strong><?php echo __('Set a new due date'); ?></strong></label>
                    <div class="faded"><?php echo __('Overrides the SLA plan for this ticket'); ?></div>
                    <div class="sla-adjust-field">
                        <input type="text" class="dp" name="duedate" autocomplete="off" placeholder="<?php echo __('Date'); ?>" value="<?php echo Format::htmlchars($_POST['duedate'] ?? ''); ?>">
                        <span class="error"><?php echo $errors['duedate'] ?? ''; ?></span>
                    </div>
                </td>
            </tr>
            <tr>
                <td><input type="radio" name="mode" value="hold" id="sla-mode-hold"></td>
                <td>
                    <label for="sla-mode-hold"><strong><?php echo __('Put on hold until the date above'); ?></strong></label>
                    <div class="faded"><?php echo __('Use when waiting on a vendor, a part or the requester'); ?></div>
                </td>
            </tr>
            <tr>
                <td><input type="radio" name="mode" value="reset" id="sla-mode-reset"></td>
                <td>
                    <label for="sla-mode-reset"><strong><?php echo __('Reset to the SLA plan'); ?></strong></label>
                    <div class="faded"><?php echo __('Removes any manual due date and recomputes the target from the plan'); ?></div>
                </td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top:10px">
                    <label for="sla-reason"><strong><?php echo __('Reason'); ?></strong> <span class="error">*</span></label>
                    <textarea name="reason" id="sla-reason" rows="3" style="width:100%;box-sizing:border-box" placeholder="<?php echo __('Why is the SLA being adjusted? This is recorded in the ticket thread.'); ?>"><?php echo Format::htmlchars($_POST['reason'] ?? ''); ?></textarea>
                    <span class="error"><?php echo $errors['reason'] ?? ''; ?></span>
                </td>
            </tr>
        </tbody>
    </table>
<?php if (count($events)) { ?>
    <div class="sla-history">
        <strong><?php echo __('Recent SLA activity'); ?></strong>
        <ul>
<?php   foreach ($events as $e) { ?>
            <li>
                <span class="faded"><?php echo Format::datetime($e->getCreateDate()); ?></span>
                <b><?php echo Format::htmlchars($e->getTypeLabel()); ?></b>
                <?php if ($e->getStep()) echo sprintf(' #%d', $e->getStep()); ?>
                <?php if (($s = $e->getStaff())) echo ' · ' . Format::htmlchars($s->getName()); ?>
                <div class="faded truncate" style="max-width:100%"><?php echo Format::htmlchars(Format::truncate($e->getDetail(), 160)); ?></div>
            </li>
<?php   } ?>
        </ul>
    </div>
<?php } ?>
    <hr>
    <p class="full-width">
        <span class="buttons pull-left">
            <input type="button" name="cancel" class="close" value="<?php echo __('Cancel'); ?>">
        </span>
        <span class="buttons pull-right">
            <input type="submit" value="<?php echo __('Apply adjustment'); ?>">
        </span>
    </p>
</form>
</div>
<div class="clear"></div>
<script type="text/javascript">
$(function() {
    var $form = $('#sla-adjust');
    $form.find('input.dp').datetimepicker({
        minDate: new Date(),
        numberOfMonths: 2,
        showButtonPanel: true,
        timeFormat: '<?php echo Format::dtfmt_php2js($cfg->getTimeFormat(true)); ?>',
        dateFormat: '<?php echo Format::dtfmt_php2js($cfg->getDateFormat(true)); ?>'
    });
    $form.find('input[name=duedate]').on('focus', function() {
        $form.find('#sla-mode-set').prop('checked', true);
    });
    $form.find('input[name=hours]').on('focus', function() {
        $form.find('#sla-mode-extend').prop('checked', true);
    });
});
</script>
