<?php
/*********************************************************************
    class.sla_escalation.php

    SLA escalation engine and per-ticket SLA adjustments.

      * Warnings when a configurable share of the grace period has
        elapsed ("at risk")
      * First-response targets
      * Escalation steps when the due date is breached (raise priority,
        assign to an agent / team / manager, transfer, notify people
        and connector channels, add a note), applied by cron
      * Per-ticket adjustments (extend, set, hold, reset) with an audit
        trail in the ticket thread and the %sla_event table

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

require_once INCLUDE_DIR.'class.sla.php';

class SLAEvent extends VerySimpleModel {
    static $meta = array(
        'table' => SLA_EVENT_TABLE,
        'pk' => array('id'),
        'ordering' => array('-id'),
        'joins' => array(
            'ticket' => array(
                'constraint' => array('ticket_id' => 'Ticket.ticket_id'),
                'null' => true,
            ),
            'sla' => array(
                'constraint' => array('sla_id' => 'SLA.id'),
                'null' => true,
            ),
            'staff' => array(
                'constraint' => array('staff_id' => 'Staff.staff_id'),
                'null' => true,
            ),
        ),
    );

    const TYPE_WARNING  = 'warning';
    const TYPE_RESPONSE = 'response';
    const TYPE_BREACH   = 'breach';
    const TYPE_STEP     = 'step';
    const TYPE_ADJUST   = 'adjust';

    static $types = array(
        self::TYPE_WARNING => /* @trans */ 'At risk',
        self::TYPE_RESPONSE => /* @trans */ 'Response target missed',
        self::TYPE_BREACH => /* @trans */ 'Overdue',
        self::TYPE_STEP => /* @trans */ 'Escalation',
        self::TYPE_ADJUST => /* @trans */ 'Adjustment',
    );

    function getId() { return $this->id; }
    function getType() { return $this->type; }
    function getStep() { return (int) $this->step; }
    function getDetail() { return $this->detail; }
    function getCreateDate() { return $this->created; }
    function getStaff() { return $this->staff; }

    function getTypeLabel() {
        return isset(self::$types[$this->type]) ? __(self::$types[$this->type]) : ucfirst((string) $this->type);
    }

    static function log(Ticket $ticket, $type, $detail='', $step=0, $staff=null) {
        $e = new static(array(
            'ticket_id' => $ticket->getId(),
            'sla_id' => (int) $ticket->getSLAId(),
            'type' => $type,
            'step' => (int) $step,
            'staff_id' => $staff instanceof Staff ? $staff->getId() : (int) $staff,
            'detail' => mb_substr((string) $detail, 0, 4000),
            'created' => SqlFunction::NOW(),
        ));
        return $e->save() ? $e : null;
    }

    static function forTicket($ticket, $limit=20) {
        $id = $ticket instanceof Ticket ? $ticket->getId() : (int) $ticket;
        return self::objects()->filter(array('ticket_id' => $id))->order_by('-id')->limit($limit);
    }

    /**
     * Has an event of the given type (and step) been logged for the
     * ticket since it was last (re)opened?
     */
    static function exists(Ticket $ticket, $type, $step=null) {
        $q = self::objects()->filter(array(
            'ticket_id' => $ticket->getId(),
            'type' => $type,
        ));
        if ($step !== null)
            $q = $q->filter(array('step' => (int) $step));
        if (($since = $ticket->getReopenDate()))
            $q = $q->filter(array('created__gte' => $since));
        return $q->exists();
    }

    static function countSince($type, $since) {
        return self::objects()->filter(array(
            'type' => $type,
            'created__gte' => $since,
        ))->count();
    }
}

class SLAEscalator {
    static $bootstrapped = false;

    static function bootstrap() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;
        Signal::connect('cron', array('SLAEscalator', 'onCron'));
        Signal::connect('ticket.overdue', array('SLAEscalator', 'onOverdue'));
    }

    static function onCron($object=null, &$data=null) {
        try {
            self::run();
        }
        catch (Throwable $t) {
            global $ost;
            if ($ost)
                $ost->logWarning('SLA escalation', sprintf("%s\n%s:%d", $t->getMessage(),
                    $t->getFile(), $t->getLine()), false);
        }
    }

    /**
     * Immediate escalation steps (after <= 0) when a ticket is marked
     * overdue, so the first action does not wait for the next cron run
     */
    static function onOverdue($ticket, &$data=null) {
        if (!$ticket instanceof Ticket)
            return;
        try {
            SLAEvent::log($ticket, SLAEvent::TYPE_BREACH, sprintf(__('Due %s'),
                $ticket->getEstDueDate() ? Format::datetime($ticket->getEstDueDate()) : ''));
            self::escalate($ticket, 0);
        }
        catch (Throwable $t) {
            global $ost;
            if ($ost)
                $ost->logWarning('SLA escalation', $t->getMessage(), false);
        }
    }

    /* ----------------------- Cron passes ----------------------- */

    static function run($max=300) {
        $n = 0;
        $n += self::checkWarnings($max);
        $n += self::checkResponseTargets($max);
        $n += self::checkEscalations($max);
        return $n;
    }

    /**
     * "At risk" warnings: a configurable percentage of the grace period
     * has elapsed without the ticket being resolved
     */
    static function checkWarnings($max=300) {
        $tickets = Ticket::objects()->filter(array(
            'status__state' => 'open',
            'isoverdue' => 0,
            'est_duedate__isnull' => false,
            'sla__warn_percent__gt' => 0,
        ))->order_by('est_duedate')->limit($max);
        $n = 0;
        $now = Misc::gmtime();
        foreach ($tickets as $ticket) {
            $sla = $ticket->getSLA();
            if (!$sla || !$sla->isActive())
                continue;
            if ($sla->pausesWhenAnswered() && $ticket->isAnswered())
                continue;
            $p = self::progress($ticket);
            if ($p['state'] == 'none' || $p['percent'] < $sla->getWarnPercent())
                continue;
            if (SLAEvent::exists($ticket, SLAEvent::TYPE_WARNING))
                continue;
            $detail = sprintf(__('%d%% of the SLA window elapsed, due %s'),
                (int) $p['percent'], Format::datetime($ticket->getEstDueDate()));
            SLAEvent::log($ticket, SLAEvent::TYPE_WARNING, $detail);
            self::notifyPeople($ticket, array('assignee', 'manager'),
                sprintf(__('SLA at risk: ticket #%s'), $ticket->getNumber()),
                sprintf("%s\n\n%s\n%s", $ticket->getSubject(), $detail, self::ticketUrl($ticket)));
            ConnectorManager::notifyTicket($ticket, array(
                'kind' => 'sla', 'event' => 'warning', 'severity' => 'warning',
                'title' => sprintf(__('SLA at risk: ticket #%s'), $ticket->getNumber()),
                'text' => sprintf("%s\n%s", $ticket->getSubject(), $detail),
                'facts' => self::facts($ticket),
            ));
            $n++;
        }
        return $n;
    }

    /**
     * First response targets: no agent response within the configured
     * number of hours after the ticket was opened
     */
    static function checkResponseTargets($max=300) {
        $tickets = Ticket::objects()->filter(array(
            'status__state' => 'open',
            'isanswered' => 0,
            'sla__response_period__gt' => 0,
            'thread__lastresponse__isnull' => true,
        ))->order_by('created')->limit($max);
        $n = 0;
        $now = Misc::gmtime();
        foreach ($tickets as $ticket) {
            $sla = $ticket->getSLA();
            if (!$sla || !$sla->isActive() || !$sla->getResponsePeriod())
                continue;
            $start = Misc::db2gmtime($ticket->getReopenDate() ?: $ticket->getCreateDate());
            if ($now - $start < $sla->getResponsePeriod() * 3600)
                continue;
            if (SLAEvent::exists($ticket, SLAEvent::TYPE_RESPONSE))
                continue;
            $detail = sprintf(__('No agent response within %s hours'), $sla->getResponsePeriod());
            SLAEvent::log($ticket, SLAEvent::TYPE_RESPONSE, $detail);
            $ticket->logNote(__('SLA response target missed'), $detail, 'SLA', false);
            self::notifyPeople($ticket, array('assignee', 'manager', 'dept'),
                sprintf(__('Response target missed: ticket #%s'), $ticket->getNumber()),
                sprintf("%s\n\n%s\n%s", $ticket->getSubject(), $detail, self::ticketUrl($ticket)));
            ConnectorManager::notifyTicket($ticket, array(
                'kind' => 'sla', 'event' => 'response', 'severity' => 'warning',
                'title' => sprintf(__('Response target missed: ticket #%s'), $ticket->getNumber()),
                'text' => sprintf("%s\n%s", $ticket->getSubject(), $detail),
                'facts' => self::facts($ticket),
            ));
            $n++;
        }
        return $n;
    }

    /**
     * Apply escalation steps to overdue tickets
     */
    static function checkEscalations($max=300) {
        $tickets = Ticket::objects()->filter(array(
            'status__state' => 'open',
            'isoverdue' => 1,
            'sla__escalation_rules__isnull' => false,
        ))->exclude(array('sla__escalation_rules' => ''))
          ->order_by('est_duedate')->limit($max);
        $n = 0;
        foreach ($tickets as $ticket)
            $n += self::escalate($ticket);
        return $n;
    }

    /**
     * Apply all escalation steps whose delay has passed and which have
     * not been applied yet. Returns the number of steps applied.
     *
     * $maxAfter - only apply steps with "after" <= $maxAfter hours
     *             (null = based on actual hours overdue)
     */
    static function escalate(Ticket $ticket, $maxAfter=null) {
        if (!$ticket->isOpen())
            return 0;
        $sla = $ticket->getSLA();
        if (!$sla || !$sla->isActive())
            return 0;
        if ($sla->pausesWhenAnswered() && $ticket->isAnswered())
            return 0;
        $rules = $sla->getEscalationRules();
        if (!$rules)
            return 0;
        $due = $ticket->getEstDueDate();
        if (!$due)
            return 0;
        $hours = (Misc::gmtime() - Misc::db2gmtime($due)) / 3600;
        $applied = 0;
        foreach ($rules as $idx => $step) {
            $after = isset($step['after']) ? (float) $step['after'] : 0;
            if ($maxAfter !== null ? $after > $maxAfter : $after > $hours)
                continue;
            if (SLAEvent::exists($ticket, SLAEvent::TYPE_STEP, $idx + 1))
                continue;
            self::applyStep($ticket, $sla, $idx + 1, $step);
            $applied++;
        }
        return $applied;
    }

    static function applyStep(Ticket $ticket, SLA $sla, $number, array $step) {
        $actions = array();
        $dept = $ticket->getDept();

        // Priority
        if (!empty($step['priority_id']) && ($p = Priority::lookup((int) $step['priority_id']))) {
            if ((int) $ticket->getPriorityId() !== (int) $p->getId()) {
                self::setPriority($ticket, $p->getId());
                $actions[] = sprintf(__('priority set to %s'), $p->getDesc());
            }
        }

        // Transfer
        if (!empty($step['dept_id']) && (int) $step['dept_id'] !== (int) $ticket->getDeptId()
                && ($newDept = Dept::lookup((int) $step['dept_id'])) && $newDept->isActive()) {
            if ($ticket->setDeptId($newDept->getId())) {
                $ticket->logEvent('transferred', array('dept' => $newDept->getName()));
                $actions[] = sprintf(__('transferred to %s'), $newDept->getName());
                $dept = $newDept;
            }
        }

        // Assignment
        $assignee = isset($step['assignee']) ? (string) $step['assignee'] : '';
        if ($assignee === 'manager') {
            if ($dept && ($m = $dept->getManager()) && $m->isAvailable()
                    && (int) $ticket->getStaffId() !== (int) $m->getId()
                    && $ticket->assignToStaff($m, __('SLA escalation'), true, 'SLA'))
                $actions[] = sprintf(__('assigned to %s'), $m->getName());
        }
        elseif ($assignee && $assignee[0] == 's' && ($s = Staff::lookup((int) substr($assignee, 1)))) {
            if ((int) $ticket->getStaffId() !== (int) $s->getId()
                    && $ticket->assignToStaff($s, __('SLA escalation'), true, 'SLA'))
                $actions[] = sprintf(__('assigned to %s'), $s->getName());
        }
        elseif ($assignee && $assignee[0] == 't' && ($t = Team::lookup((int) substr($assignee, 1)))) {
            if ((int) $ticket->getTeamId() !== (int) $t->getId()
                    && $ticket->assignToTeam($t, __('SLA escalation'), true, 'SLA'))
                $actions[] = sprintf(__('assigned to team %s'), $t->getName());
        }

        $hours = isset($step['after']) ? (float) $step['after'] : 0;
        $title = sprintf(__('SLA escalation step %d (%s)'), $number,
            $hours <= 0 ? __('on breach') : sprintf(__('%s hours after due'), rtrim(rtrim(number_format($hours, 1), '0'), '.')));
        $summary = $actions ? implode(', ', $actions) : __('no changes required');
        $note = !empty($step['note']) ? (string) $step['note'] : '';
        $text = trim($summary . ($note ? "\n\n" . $note : ''));

        SLAEvent::log($ticket, SLAEvent::TYPE_STEP, $text, $number);
        $ticket->logNote($title, $text, 'SLA', false);

        // Notifications
        $notify = isset($step['notify']) && is_array($step['notify']) ? $step['notify'] : array();
        $people = array_values(array_intersect($notify, array('assignee', 'manager', 'dept')));
        if ($people)
            self::notifyPeople($ticket, $people,
                sprintf(__('%s: ticket #%s'), $title, $ticket->getNumber()),
                sprintf("%s\n\n%s\n%s", $ticket->getSubject(), $text, self::ticketUrl($ticket)));
        if (in_array('channels', $notify))
            ConnectorManager::notifyTicket($ticket, array(
                'kind' => 'sla', 'event' => 'escalated', 'severity' => 'error',
                'title' => sprintf(__('%s: ticket #%s'), $title, $ticket->getNumber()),
                'text' => sprintf("%s\n%s", $ticket->getSubject(), $text),
                'facts' => self::facts($ticket),
            ));
        $type = array('type' => 'escalated', 'step' => $number);
        Signal::send('object.edited', $ticket, $type);
        return true;
    }

    static function setPriority(Ticket $ticket, $priorityId) {
        foreach (DynamicFormEntry::forTicket($ticket->getId()) as $form) {
            if ($form->getAnswer('priority')) {
                $form->setAnswer('priority', null, $priorityId);
                $form->save();
            }
        }
        $ticket->loadDynamicData(true);
    }

    /**
     * Email agents about an SLA event (assignee / team, department
     * manager, department members)
     */
    static function notifyPeople(Ticket $ticket, array $who, $subject, $body) {
        global $cfg;
        $dept = $ticket->getDept();
        if (!$dept || !($email = $dept->getAlertEmail()))
            return 0;
        $recipients = array();
        if (in_array('assignee', $who)) {
            if (($s = $ticket->getStaff()))
                $recipients[] = $s;
            elseif (($t = $ticket->getTeam()))
                foreach ($t->getMembersForAlerts() as $m)
                    $recipients[] = $m;
        }
        if (in_array('manager', $who) && ($m = $dept->getManager()))
            $recipients[] = $m;
        if (in_array('dept', $who))
            foreach ($dept->getMembersForAlerts() as $m)
                $recipients[] = $m;
        $sent = array();
        $n = 0;
        foreach ($recipients as $staff) {
            if (!$staff instanceof Staff || !$staff->isAvailable())
                continue;
            if (in_array($staff->getEmail(), $sent))
                continue;
            $sent[] = $staff->getEmail();
            $email->sendAlert($staff, sprintf('[%s] %s', $cfg->getTitle(), $subject), $body, null,
                array('text' => true, 'reply-tag' => false));
            $n++;
        }
        return $n;
    }

    /* ----------------------- Adjustments ----------------------- */

    /**
     * Adjust the SLA of a ticket.
     *
     * $vars: mode (extend|set|hold|reset), hours, duedate, reason
     */
    static function adjust(Ticket $ticket, array $vars, $staff, &$errors) {
        $mode = isset($vars['mode']) ? $vars['mode'] : '';
        $reason = trim((string) (isset($vars['reason']) ? $vars['reason'] : ''));
        if (!$reason)
            $errors['reason'] = __('Please give a reason for the adjustment');
        if ($ticket->isClosed())
            $errors['err'] = __('The SLA of a closed ticket cannot be adjusted');

        $now = Misc::gmtime();
        $newDue = null;
        switch ($mode) {
        case 'extend':
            $hours = isset($vars['hours']) ? (float) $vars['hours'] : 0;
            if ($hours <= 0 || $hours > 8760)
                $errors['hours'] = __('Enter the number of hours to extend by (1 – 8760)');
            else {
                $base = $ticket->getEstDueDate() ? Misc::db2gmtime($ticket->getEstDueDate()) : $now;
                $newDue = max($base, $now) + (int) round($hours * 3600);
            }
            break;
        case 'set':
        case 'hold':
            $when = isset($vars['duedate']) ? trim((string) $vars['duedate']) : '';
            if (!$when || ($ts = Misc::user2gmtime($when, $staff)) === false || !$ts)
                $errors['duedate'] = __('Enter a valid date and time');
            elseif ($ts <= $now)
                $errors['duedate'] = __('The new due date must be in the future');
            else
                $newDue = $ts;
            break;
        case 'reset':
            break;
        default:
            $errors['mode'] = __('Select an adjustment');
        }
        if ($errors)
            return false;

        $before = $ticket->getEstDueDate() ? Format::datetime($ticket->getEstDueDate()) : __('none');
        if ($mode == 'reset') {
            $ticket->duedate = null;
            $ticket->updateEstDueDate(true);
            $after = $ticket->getEstDueDate() ? Format::datetime($ticket->getEstDueDate()) : __('none');
            $what = sprintf(__('SLA reset to plan default (%s)'), $after);
        }
        else {
            $ticket->duedate = self::gm2dbtime($newDue);
            if ($ticket->isOverdue())
                $ticket->clearOverdue(false);
            $ticket->save();
            $after = Format::datetime($ticket->getDueDate());
            if ($mode == 'extend')
                $what = sprintf(__('Due date extended by %s hours to %s'), rtrim(rtrim(number_format((float) $vars['hours'], 1), '0'), '.'), $after);
            elseif ($mode == 'hold')
                $what = sprintf(__('SLA on hold until %s'), $after);
            else
                $what = sprintf(__('Due date set to %s'), $after);
        }

        $text = sprintf("%s\n%s: %s\n%s: %s", $what, __('Previous'), $before, __('Reason'), $reason);
        SLAEvent::log($ticket, SLAEvent::TYPE_ADJUST, $text, 0, $staff);
        $ticket->logNote(__('SLA adjusted'), nl2br(Format::htmlchars($text)), $staff, false);
        $ticket->logEvent('edited', array('fields' => array('duedate' => $after)), $staff);
        return true;
    }

    /* ----------------------- Presentation ----------------------- */

    /**
     * Progress of the ticket against its SLA window
     */
    static function progress(Ticket $ticket) {
        $info = array('state' => 'none', 'percent' => 0, 'remaining' => null,
            'label' => '', 'due' => null, 'start' => null, 'warn' => 0);
        if ($ticket->isClosed()) {
            $info['state'] = 'closed';
            $info['label'] = __('Closed');
            return $info;
        }
        $due = $ticket->getEstDueDate();
        if (!$due)
            return $info;
        $sla = $ticket->getSLA();
        $now = Misc::gmtime();
        $dueTs = Misc::db2gmtime($due);
        $startTs = Misc::db2gmtime($ticket->getReopenDate() ?: $ticket->getCreateDate());
        $total = max(1, $dueTs - $startTs);
        $elapsed = $now - $startTs;
        $info['due'] = $dueTs;
        $info['start'] = $startTs;
        $info['percent'] = min(100, max(0, round($elapsed / $total * 100)));
        $info['remaining'] = $dueTs - $now;
        $info['warn'] = $sla ? (int) $sla->getWarnPercent() : 0;
        if ($ticket->isOverdue() || $now > $dueTs) {
            $info['state'] = 'overdue';
            $info['label'] = sprintf(__('%s overdue'), self::humanDuration($now - $dueTs));
        }
        elseif ($sla && $sla->pausesWhenAnswered() && $ticket->isAnswered()) {
            $info['state'] = 'paused';
            $info['label'] = __('Paused (awaiting requester)');
        }
        elseif ($info['warn'] && $info['percent'] >= $info['warn']) {
            $info['state'] = 'warning';
            $info['label'] = sprintf(__('%s left'), self::humanDuration($dueTs - $now));
        }
        else {
            $info['state'] = 'ok';
            $info['label'] = sprintf(__('%s left'), self::humanDuration($dueTs - $now));
        }
        return $info;
    }

    /**
     * Format a GMT timestamp as a database datetime string
     */
    static function gm2dbtime($ts) {
        global $cfg;
        $tz = new DateTimeZone($cfg->getDbTimezone());
        $D = DateTime::createFromFormat('U', (int) $ts);
        return gmdate('Y-m-d H:i:s', (int) $ts + $tz->getOffset($D));
    }

    static function humanDuration($seconds) {
        $seconds = abs((int) $seconds);
        if ($seconds < 60)
            return __('less than a minute');
        $d = floor($seconds / 86400);
        $h = floor(($seconds % 86400) / 3600);
        $m = floor(($seconds % 3600) / 60);
        if ($d >= 1)
            return $h ? sprintf('%dd %dh', $d, $h) : sprintf('%dd', $d);
        if ($h >= 1)
            return $m ? sprintf('%dh %dm', $h, $m) : sprintf('%dh', $h);
        return sprintf('%dm', $m);
    }

    static function facts(Ticket $ticket) {
        $facts = array(
            array(__('Department'), (string) $ticket->getDeptName()),
            array(__('Priority'), (string) $ticket->getPriority()),
            array(__('Assigned To'), $ticket->isAssigned()
                ? implode(', ', array_map('strval', $ticket->getAssignees())) : __('Unassigned')),
        );
        if (($sla = $ticket->getSLA()))
            $facts[] = array(__('SLA'), (string) $sla->getName());
        if (($due = $ticket->getEstDueDate()))
            $facts[] = array(__('Due'), Format::datetime($due));
        return $facts;
    }

    static function ticketUrl(Ticket $ticket) {
        global $cfg;
        return sprintf('%s/scp/tickets.php?id=%d', rtrim($cfg->getBaseUrl(), '/'), $ticket->getId());
    }

    /**
     * SLA compliance statistics for a period (dashboard / reports)
     */
    static function compliance($start, $stop, $deptIds=null) {
        $q = Ticket::objects()->filter(array(
            'created__range' => array($start, $stop, true),
        ));
        if ($deptIds)
            $q = $q->filter(array('dept_id__in' => $deptIds));
        $total = $q->count();
        $breached = (clone $q)->filter(array('isoverdue' => 1))->count()
            + (clone $q)->filter(array('isoverdue' => 0, 'status__state' => 'closed',
                'est_duedate__isnull' => false, 'closed__gt' => new SqlField('est_duedate')))->count();
        return array(
            'total' => $total,
            'breached' => $breached,
            'met' => max(0, $total - $breached),
            'percent' => $total ? round(($total - $breached) / $total * 100, 1) : null,
        );
    }
}
?>
