<?php
if(!defined('OSTSCPINC') || !$thisstaff || !$incident) die('Access Denied');
$ticket = $incident->getTicket();
$manage = $canManage($incident);
$extra = $incident->getExtra();
$payload = array_diff_key($extra, array('tags' => 1, 'notified' => 1, 'last_payload' => 1));
$links = $incident->getLinks();
?>
<div class="sticky bar">
    <div class="content">
        <div class="pull-left flush-left">
            <h2><a href="incidents.php" title="<?php echo __('All incidents'); ?>"><i class="icon-chevron-left"></i></a>
                <?php echo sprintf(__('Incident #%d'), $incident->getId()); ?>
                <span class="pill pill-sev-<?php echo $incident->getSeverity(); ?>"><?php echo $incident->getSeverityLabel(); ?></span>
                <span class="pill"><?php echo $incident->getStatusLabel(); ?></span>
            </h2>
        </div>
        <div class="pull-right flush-right">
<?php if ($manage) { ?>
            <form method="post" action="incidents.php?id=<?php echo $incident->getId(); ?>" style="display:inline">
                <?php csrf_token(); ?>
<?php   if ($incident->isOpen() && !$incident->isAcknowledged()) { ?>
                <button type="submit" name="do" value="acknowledge" class="action-button"><i class="icon-eye-open"></i> <?php echo __('Acknowledge'); ?></button>
<?php   }
        if ($incident->isOpen()) { ?>
                <button type="submit" name="do" value="resolve" class="action-button"><i class="icon-ok"></i> <?php echo __('Resolve'); ?></button>
<?php   } else { ?>
                <button type="submit" name="do" value="reopen" class="action-button"><i class="icon-rotate-right"></i> <?php echo __('Reopen'); ?></button>
<?php   }
        if ($thisstaff->isAdmin()) { ?>
                <button type="submit" name="do" value="delete" class="action-button" onclick="return confirm('<?php echo __('Delete this incident?'); ?>');"><i class="icon-trash"></i> <?php echo __('Delete'); ?></button>
<?php   } ?>
            </form>
<?php } ?>
        </div>
    </div>
</div>
<div class="clear"></div>

<div class="incident-view">
    <div class="incident-main">
        <h3 class="incident-title"><?php echo Format::htmlchars($incident->getTitle()); ?></h3>
        <div class="faded"><?php echo sprintf(__('Reported by %s · key %s'), Format::htmlchars($incident->getSource()),
            '<code>' . Format::htmlchars($incident->getExternalId()) . '</code>'); ?></div>
        <div class="incident-detail"><?php echo nl2br(Format::htmlchars((string) $incident->getDetail())) ?: '<em class="faded">' . __('No details were provided.') . '</em>'; ?></div>
<?php if ($payload) { ?>
        <h4><?php echo __('Additional data'); ?></h4>
        <table class="table incident-data">
<?php foreach ($payload as $k => $v) { ?>
            <tr><th><?php echo Format::htmlchars($k); ?></th><td><?php echo Format::htmlchars(is_scalar($v) ? $v : json_encode($v)); ?></td></tr>
<?php } ?>
        </table>
<?php } ?>
<?php if ($thisstaff->isAdmin()) { ?>
        <details class="incident-howto">
            <summary><?php echo __('How systems report to this inbox'); ?></summary>
            <pre>curl -X POST <?php echo Format::htmlchars(rtrim($cfg->getBaseUrl(), '/')); ?>/api/monitor/events.json \
  -H "X-API-Key: &lt;API key with the incident scope&gt;" \
  -H "Content-Type: application/json" \
  -d '{"source": "<?php echo Format::htmlchars($incident->getSource()); ?>", "key": "<?php echo Format::htmlchars($incident->getExternalId()); ?>",
       "title": "<?php echo Format::htmlchars($incident->getTitle()); ?>", "severity": "<?php echo $incident->getSeverity(); ?>",
       "status": "resolved", "detail": "Recovered"}'</pre>
        </details>
<?php } ?>
    </div>
    <aside class="incident-side">
        <dl class="facts">
            <dt><?php echo __('Status'); ?></dt><dd><?php echo $incident->getStatusLabel(); ?></dd>
            <dt><?php echo __('Severity'); ?></dt><dd><?php echo $incident->getSeverityLabel(); ?></dd>
            <dt><?php echo __('Department'); ?></dt><dd><?php echo Format::htmlchars($incident->getDeptName()) ?: '&mdash;'; ?></dd>
            <dt><?php echo __('Ticket'); ?></dt>
            <dd><?php echo $ticket ? sprintf('<a href="tickets.php?id=%d">#%s</a> <span class="faded">(%s)</span>',
                $ticket->getId(), $ticket->getNumber(), (string) $ticket->getStatus()) : '&mdash;'; ?></dd>
            <dt><?php echo __('Occurrences'); ?></dt><dd><?php echo $incident->getOccurrences(); ?></dd>
            <dt><?php echo __('First seen'); ?></dt><dd><?php echo Format::datetime($incident->getFirstSeen()); ?></dd>
            <dt><?php echo __('Last seen'); ?></dt><dd><?php echo Format::datetime($incident->getLastSeen()); ?></dd>
<?php if ($incident->getAcknowledgedDate()) { ?>
            <dt><?php echo __('Acknowledged'); ?></dt><dd><?php echo Format::datetime($incident->getAcknowledgedDate()); ?></dd>
<?php } ?>
<?php if ($incident->getResolvedDate()) { ?>
            <dt><?php echo __('Resolved'); ?></dt><dd><?php echo Format::datetime($incident->getResolvedDate()); ?></dd>
<?php } ?>
            <dt><?php echo __('Duration'); ?></dt><dd><?php echo Format::elapsedTime($incident->getDuration()) ?: __('less than a minute'); ?></dd>
<?php if ($incident->getUrl()) { ?>
            <dt><?php echo __('Link'); ?></dt><dd><a href="<?php echo Format::htmlchars($incident->getUrl()); ?>" target="_blank" rel="noopener"><?php echo Format::htmlchars(Format::truncate($incident->getUrl(), 40)); ?></a></dd>
<?php } ?>
<?php if (($tags = $incident->getTags())) { ?>
            <dt><?php echo __('Tags'); ?></dt><dd><?php foreach ($tags as $t) echo '<span class="pill">'.Format::htmlchars($t).'</span> '; ?></dd>
<?php } ?>
        </dl>
<?php if (count($links)) { ?>
        <h4><?php echo __('Linked in'); ?></h4>
        <ul class="external-links">
<?php foreach ($links as $l) { $c = $l->getConnector(); ?>
            <li><span class="faded"><?php echo $c ? Format::htmlchars($c->getTypeName()) : ''; ?></span>
                <?php if ($l->getExternalUrl()) { ?><a href="<?php echo Format::htmlchars($l->getExternalUrl()); ?>" target="_blank" rel="noopener"><?php echo Format::htmlchars($l->getLabel()); ?></a><?php } else echo Format::htmlchars($l->getLabel()); ?></li>
<?php } ?>
        </ul>
<?php } ?>
    </aside>
</div>
