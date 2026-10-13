<?php
$sort = 'id';
if ($options['sort'] && !strcasecmp($options['sort'], 'DESC'))
    $sort = '-id';

$cmp = function ($a, $b) use ($sort) {
    return ($sort == 'id')
        ? ($a < $b) : $a > $b;
};

$events = $events->order_by($sort);
$eventCount = count($events);
$events = new IteratorIterator($events->getIterator());
$events->rewind();
$event = $events->current();
$htmlId = $options['html-id'] ?: ('thread-'.$this->getId());

$thread_attachments = array();
foreach (Attachment::objects()->filter(array(
    'thread_entry__thread__id' => $this->getId(),
))->select_related('thread_entry', 'file') as $att) {
    $thread_attachments[$att->object_id][] = $att;
}

$tid = $this->getObJectId();
if ($this->getObjectType() == 'T')
    $ticket = Ticket::lookup($tid);

// Day separators between entries (osTicketCustom)
$lastDay = null;
$daySeparator = function($timestamp) use (&$lastDay) {
    $day = Format::date($timestamp);
    if ($day === $lastDay)
        return;
    $lastDay = $day;
    $today = Format::date(Misc::gmtime(), false);
    $yesterday = Format::date(Misc::gmtime() - 86400, false);
    $label = $day;
    if ($day === $today)
        $label = __('Today');
    elseif ($day === $yesterday)
        $label = __('Yesterday');
    echo sprintf('<div class="thread-day-separator" data-day="%s">%s</div>',
        Format::htmlchars($day), Format::htmlchars($label));
};
?>
<div id="<?php echo $htmlId; ?>">
    <div id="thread-items" data-thread-id="<?php echo $this->getId(); ?>"
        data-jump-label="<?php echo __('Latest'); ?>">
    <?php
    if ($entries->exists(true)) {
        $buckets = ThreadEntry::sortEntries($entries, $ticket);
        foreach ($buckets as $entry) {
            $entry = ThreadEntry::lookup($entry->id);
            $extra = $entry->getMergeData();
            if ($entry->hasFlag(ThreadEntry::FLAG_CHILD) && $extra) {
                if (!is_array($extra))
                    $extra = json_decode($extra, true);
                if (!$thread = Thread::objects()->filter(array('id'=>$extra['thread']))->values_flat('extra'))
                    continue;
                foreach ($thread as $t)
                    $threadExtra = $t[0];
                $threadExtra = json_decode($threadExtra, true);
                $number = $threadExtra['number'];
            } else
                $number = null;

            // Emit all events prior to this entry
            while ($event && $cmp($event->timestamp, $entry->created)) {
                $daySeparator($event->timestamp);
                $event->render(ThreadEvent::MODE_STAFF);
                $events->next();
                $event = $events->current();
            }
            $daySeparator($entry->created);
            ?><div id="thread-entry-<?php echo $entry->getId(); ?>"><?php
            include STAFFINC_DIR . 'templates/thread-entry.tmpl.php';
            ?></div><?php
        }
    }

    // Emit all other events
    while ($event) {
        $daySeparator($event->timestamp);
        $event->render(ThreadEvent::MODE_STAFF);
        $events->next();
        $event = $events->current();
    }
    // This should never happen
    if (count($entries) + $eventCount == 0) {
        echo '<p><em>'.__('No entries have been posted to this thread.').'</em></p>';
    }
    ?>
    </div>
</div>
<script type="text/javascript">
    $(function() {
        var container = '<?php echo $htmlId; ?>';

        // Set inline image urls.
        <?php
        $urls = array();
        foreach ($thread_attachments as $eid=>$atts) {
            foreach ($atts as $A) {
                if (!$A->inline)
                    continue;
                $urls[strtolower($A->file->getKey())] = array(
                    'download_url' => $A->file->getDownloadUrl(['id' =>
                        $A->getId()]),
                    'filename' => $A->getFilename(),
                );
            }
        }
        ?>
        $('#'+container).data('imageUrls', <?php echo JsonDataEncoder::encode($urls); ?>);
        // Trigger thread processing.
        if ($.thread)
            $.thread.onLoad(container,
                    {autoScroll: <?php echo $sort == 'id' ? 'true' : 'false'; ?>});
    });
</script>
