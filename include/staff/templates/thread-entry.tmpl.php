<?php
global $thisstaff, $cfg;
$timeFormat = null;
if ($thisstaff && !strcasecmp($thisstaff->datetime_format, 'relative')) {
    $timeFormat = function($datetime) {
        return Format::relativeTime(Misc::db2gmtime($datetime));
    };
}

$entryTypes = array('M'=>'message', 'R'=>'response', 'N'=>'note');
$entryLabels = array('M'=>__('Message'), 'R'=>__('Reply'), 'N'=>__('Internal note'));
$user = $entry->getUser() ?: $entry->getStaff();
$name = $user ? $user->getName() : $entry->poster;
$avatar = '';
if ($user && $cfg->isAvatarsEnabled())
    $avatar = $user->getAvatar();
$initials = '';
if (!$avatar) {
    $parts = preg_split('/\s+/', trim((string) $name));
    foreach (array_slice($parts, 0, 2) as $part)
        $initials .= mb_strtoupper(mb_substr($part, 0, 1));
    $initials = $initials ?: '?';
}
$source = (string) $entry->getSource();
$sourceGlyphs = array('email' => 'icon-envelope', 'web' => 'icon-globe', 'phone' => 'icon-phone',
    'api' => 'icon-link', 'linear' => 'icon-random', 'teams' => 'icon-comments', 'monitor' => 'icon-signal');
$sourceGlyph = isset($sourceGlyphs[strtolower($source)]) ? $sourceGlyphs[strtolower($source)] : '';
?>
<div class="thread-entry <?php
    echo $entry->isSystem() ? 'system' : $entryTypes[$entry->type]; ?> <?php if ($avatar) echo 'avatar'; ?>" style="position:relative;z-index:auto;">
<?php if ($avatar) { ?>
    <span class="<?php echo ($entry->type == 'M') ? 'pull-right' : 'pull-left'; ?> avatar">
<?php echo $avatar; ?>
    </span>
<?php } ?>
    <div class="header">
        <span class="poster-avatar"><?php echo $avatar ?: Format::htmlchars($initials); ?></span>
        <span class="poster-name"><?php echo Format::htmlchars($name); ?></span>
        <span class="entry-kind"><?php echo $entry->isSystem() ? __('System') : $entryLabels[$entry->type]; ?></span>
<?php   if ($source && strcasecmp($source, 'web')) { ?>
        <span class="entry-source" title="<?php echo sprintf(__('Posted via %s'), Format::htmlchars($source)); ?>"><?php
            if ($sourceGlyph) echo '<i class="'.$sourceGlyph.'"></i> ';
            echo Format::htmlchars($source); ?></span>
<?php   } ?>
        <a name="entry-<?php echo $entry->id; ?>" href="#entry-<?php echo $entry->id; ?>"><time <?php
            echo $timeFormat ? 'class="relative"' : ''; ?> datetime="<?php echo date(DateTime::W3C, Misc::db2gmtime($entry->created)); ?>"
            data-toggle="tooltip" title="<?php echo Format::daydatetime($entry->created); ?>"><?php
            echo $timeFormat ? $timeFormat($entry->created) : Format::datetime($entry->created); ?></time></a>
        <div class="pull-right">
        <span class="textra light">
<?php   if ($entry->flags & ThreadEntry::FLAG_EDITED) { ?>
            <span class="label label-bare" title="<?php
            echo sprintf(__('Edited on %s by %s'), Format::datetime($entry->updated),
                ($editor = $entry->getEditor()) ? $editor->getName() : '');
                ?>"><?php echo __('Edited'); ?></span>
<?php   }
        if ($entry->flags & ThreadEntry::FLAG_RESENT) { ?>
            <span class="label label-bare"><?php echo __('Resent'); ?></span>
<?php   }
        if ($entry->flags & ThreadEntry::FLAG_REPLY_ALL) { ?>
            <span class="label label-bare" title="<?php echo __('Reply to all'); ?>"><i class="icon-group"></i></span>
<?php   }
        if ($entry->flags & ThreadEntry::FLAG_REPLY_USER) { ?>
            <span class="label label-bare" title="<?php echo __('Reply to requester'); ?>"><i class="icon-user"></i></span>
<?php   }
        if ($ticket && (get_class($this) != 'TaskThread' && $entry->thread_id != $ticket->getThreadId()) || $entry->getMergeData()) {
            if ($number) { ?>
                <span data-toggle="tooltip" title="<?php echo sprintf(__('Ticket #%s'), $number); ?>" class="label label-bare"><i class="icon-code-fork"></i></span>
    <?php   }
        }
        if ($entry->flags & ThreadEntry::FLAG_COLLABORATOR && $entry->type == 'M') { ?>
            <span class="label label-bare"><?php echo __('Cc Collaborator'); ?></span>
        <?php   } ?>
        </span>
<?php   if ($entry->hasActions()) {
            $actions = $entry->getActions(); ?>
        <span class="muted-button pull-right" data-dropdown="#entry-action-more-<?php echo $entry->getId(); ?>" title="<?php echo __('More'); ?>">
            <i class="icon-ellipsis-horizontal"></i>
        </span>
        <div id="entry-action-more-<?php echo $entry->getId(); ?>" class="action-dropdown anchor-right">
            <ul class="title">
<?php       foreach ($actions as $group => $list) {
                foreach ($list as $id => $action) { ?>
                <li>
                    <a class="no-pjax" href="#" onclick="javascript:
                    <?php echo str_replace('"', '\\"', $action->getJsStub()); ?>; return false;">
                    <i class="<?php echo $action->getIcon(); ?>"></i> <?php
                    echo $action->getName();
                ?></a></li>
<?php           }
            } ?>
            </ul>
        </div>
<?php   } ?>
        </div>
<?php   if ($entry->title && strcasecmp($entry->title, (string) ($ticket ? $ticket->getSubject() : ''))) { ?>
        <span class="faded title truncate"><?php
            echo Format::htmlchars($entry->title); ?>
        </span>
<?php   } ?>
    </div>
    <div class="thread-body no-pjax" data-expand-label="<?php echo __('Show full message'); ?>">
        <div><?php echo $entry->getBody()->toHtml(); ?></div>
        <div class="clear"></div>
<?php
    // The strangeness here is because .has_attachments is an annotation from
    // Thread::getEntries(); however, this template may be used in other
    // places such as from thread entry editing
    $atts = isset($thread_attachments) ? $thread_attachments[$entry->id] : $entry->attachments;
    if (isset($atts) && $atts) {
?>
    <div class="attachments"><?php
        foreach ($atts as $A) {
            if ($A->inline)
                continue;
            $size = '';
            if ($A->file->size)
                $size = sprintf('<small class="filesize faded">%s</small>', Format::file_size($A->file->size));
?>
        <span class="attachment-info">
        <i class="icon-paperclip icon-flip-horizontal"></i>
        <a class="no-pjax truncate filename" href="<?php echo
        $A->file->getDownloadUrl(['id' => $A->getId()]);
            ?>" download="<?php echo Format::htmlchars($A->getFilename()); ?>"
            target="_blank"><?php echo Format::htmlchars($A->getFilename());
        ?></a><?php echo $size;?>
        </span>
<?php   }
    echo '</div>';
    }
?>
    </div>
<?php
    if (!isset($thread_attachments) && ($urls = $entry->getAttachmentUrls())) { ?>
        <script type="text/javascript">
            $('#thread-entry-<?php echo $entry->getId(); ?>')
                .data('urls', <?php
                    echo JsonDataEncoder::encode($urls); ?>)
                .data('id', <?php echo $entry->getId(); ?>);
        </script>
<?php
    } ?>
</div>
