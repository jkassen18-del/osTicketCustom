<?php
/*********************************************************************
    class.incident.php

    System monitoring inbox. External systems (monitoring tools, CI,
    cron jobs, applications) report events through the API
    (POST /api/monitor/events.json). Events are de-duplicated into
    incidents, tickets are opened in the mapped department and the
    department's Linear / Teams / webhook channels are notified with
    the full detail of what happened.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

require_once INCLUDE_DIR.'class.connector.php';

class Incident extends VerySimpleModel {
    static $meta = array(
        'table' => INCIDENT_TABLE,
        'pk' => array('id'),
        'ordering' => array('-last_seen'),
        'joins' => array(
            'dept' => array(
                'constraint' => array('dept_id' => 'Dept.id'),
                'null' => true,
            ),
            'ticket' => array(
                'constraint' => array('ticket_id' => 'Ticket.ticket_id'),
                'null' => true,
            ),
        ),
    );

    const SEV_INFO     = 'info';
    const SEV_WARNING  = 'warning';
    const SEV_ERROR    = 'error';
    const SEV_CRITICAL = 'critical';

    const STATUS_OPEN         = 'open';
    const STATUS_ACKNOWLEDGED = 'acknowledged';
    const STATUS_RESOLVED     = 'resolved';

    static $severities = array(
        self::SEV_INFO => /* @trans */ 'Info',
        self::SEV_WARNING => /* @trans */ 'Warning',
        self::SEV_ERROR => /* @trans */ 'Error',
        self::SEV_CRITICAL => /* @trans */ 'Critical',
    );

    static $statuses = array(
        self::STATUS_OPEN => /* @trans */ 'Open',
        self::STATUS_ACKNOWLEDGED => /* @trans */ 'Acknowledged',
        self::STATUS_RESOLVED => /* @trans */ 'Resolved',
    );

    var $_extra;

    function getId() { return $this->id; }
    function getSource() { return $this->source; }
    function getExternalId() { return $this->external_id; }
    function getSeverity() { return $this->severity; }
    function getStatus() { return $this->status; }
    function getTitle() { return $this->title; }
    function getDetail() { return $this->detail; }
    function getUrl() { return $this->url; }
    function getDeptId() { return $this->dept_id; }
    function getDept() { return $this->dept; }
    function getTicketId() { return $this->ticket_id; }
    function getOccurrences() { return (int) $this->occurrences; }
    function getFirstSeen() { return $this->first_seen; }
    function getLastSeen() { return $this->last_seen; }
    function getResolvedDate() { return $this->resolved; }
    function getAcknowledgedDate() { return $this->acknowledged; }
    function getCreateDate() { return $this->created; }

    function getTicket() {
        return $this->ticket_id ? Ticket::lookup((int) $this->ticket_id) : null;
    }

    function getDeptName() {
        return ($d = $this->getDept()) ? $d->getName() : '';
    }

    function getSeverityLabel() {
        return isset(self::$severities[$this->severity])
            ? __(self::$severities[$this->severity]) : ucfirst((string) $this->severity);
    }

    function getStatusLabel() {
        return isset(self::$statuses[$this->status])
            ? __(self::$statuses[$this->status]) : ucfirst((string) $this->status);
    }

    function isOpen() { return $this->status != self::STATUS_RESOLVED; }
    function isResolved() { return $this->status == self::STATUS_RESOLVED; }
    function isAcknowledged() { return $this->status == self::STATUS_ACKNOWLEDGED; }

    static function severityRank($severity) {
        $ranks = array(self::SEV_INFO => 1, self::SEV_WARNING => 2,
            self::SEV_ERROR => 3, self::SEV_CRITICAL => 4);
        return isset($ranks[$severity]) ? $ranks[$severity] : 2;
    }

    function getExtra() {
        if (!isset($this->_extra)) {
            $e = $this->extra ? json_decode($this->extra, true) : null;
            $this->_extra = is_array($e) ? $e : array();
        }
        return $this->_extra;
    }

    function setExtra(array $extra) {
        $this->_extra = $extra;
        $this->extra = json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    function getTags() {
        $e = $this->getExtra();
        return isset($e['tags']) && is_array($e['tags']) ? $e['tags'] : array();
    }

    function getDuration() {
        $start = Misc::db2gmtime($this->first_seen);
        $end = $this->resolved ? Misc::db2gmtime($this->resolved) : Misc::gmtime();
        return max(0, $end - $start);
    }

    function getLinks() {
        return ConnectorLink::objects()->filter(array(
            'object_type' => ConnectorLink::TYPE_INCIDENT,
            'object_id' => $this->getId(),
        ));
    }

    function getStaffUrl() {
        global $cfg;
        return sprintf('%s/scp/incidents.php?id=%d', rtrim($cfg->getBaseUrl(), '/'), $this->getId());
    }

    function acknowledge($staff=null, $note='') {
        if ($this->isResolved() || $this->isAcknowledged())
            return false;
        $this->status = self::STATUS_ACKNOWLEDGED;
        $this->acknowledged = SqlFunction::NOW();
        $this->updated = SqlFunction::NOW();
        if (!$this->save(true))
            return false;
        if (($ticket = $this->getTicket()))
            $ticket->logNote(__('Incident acknowledged'),
                $note ?: sprintf(__('Incident #%d acknowledged'), $this->getId()),
                $staff ?: 'Monitor', false);
        IncidentManager::notify($this, 'acknowledged', $note);
        return true;
    }

    function resolve($by=null, $note='', $closeTicket=null) {
        global $cfg;
        if ($this->isResolved())
            return true;
        $this->status = self::STATUS_RESOLVED;
        $this->resolved = SqlFunction::NOW();
        $this->updated = SqlFunction::NOW();
        if (!$this->save(true))
            return false;
        if ($closeTicket === null)
            $closeTicket = (bool) $cfg->get('incident_close_ticket', 1);
        if (($ticket = $this->getTicket())) {
            $text = sprintf(__('Incident #%d resolved%s'), $this->getId(),
                $note ? ': ' . $note : '');
            if ($closeTicket && $ticket->isOpen()) {
                $errors = array();
                $ticket->setStatus(ConnectorManager::closedStatus(), $text, $errors, false, true);
            }
            else
                $ticket->logNote(__('Incident resolved'), $text, $by ?: 'Monitor', false);
        }
        IncidentManager::notify($this, 'resolved', $note);
        return true;
    }

    function reopen() {
        if (!$this->isResolved())
            return false;
        $this->status = self::STATUS_OPEN;
        $this->resolved = null;
        $this->updated = SqlFunction::NOW();
        if (!$this->save(true))
            return false;
        if (($ticket = $this->getTicket()) && $ticket->isClosed() && $ticket->isReopenable())
            $ticket->reopen();
        return true;
    }

    function delete() {
        ConnectorLink::objects()->filter(array(
            'object_type' => ConnectorLink::TYPE_INCIDENT,
            'object_id' => $this->getId(),
        ))->delete();
        return parent::delete();
    }

    static function create($vars=false) {
        $i = new static($vars ?: array());
        $i->created = SqlFunction::NOW();
        $i->updated = SqlFunction::NOW();
        return $i;
    }

    static function lookupByKey($source, $externalId) {
        return self::objects()->filter(array(
            'source' => $source,
            'external_id' => $externalId,
        ))->first();
    }

    static function getCounts() {
        $counts = array('open' => 0, 'acknowledged' => 0, 'resolved_24h' => 0,
            'by_severity' => array());
        foreach (self::$severities as $s => $l)
            $counts['by_severity'][$s] = 0;
        foreach (self::objects()->filter(array('status__in' => array(self::STATUS_OPEN, self::STATUS_ACKNOWLEDGED)))
                ->values_flat('status', 'severity') as $row) {
            if ($row[0] == self::STATUS_OPEN) $counts['open']++;
            else $counts['acknowledged']++;
            if (isset($counts['by_severity'][$row[1]]))
                $counts['by_severity'][$row[1]]++;
        }
        $counts['resolved_24h'] = self::objects()->filter(array(
            'status' => self::STATUS_RESOLVED,
            'resolved__gt' => SqlFunction::DATE_SUB(SqlFunction::NOW(), SqlInterval::HOUR(24)),
        ))->count();
        return $counts;
    }
}

class IncidentManager {
    static $bootstrapped = false;

    static function bootstrap() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;
        Signal::connect('cron', array('IncidentManager', 'onCron'));
    }

    static function onCron($object=null, &$data=null) {
        global $cfg;
        try {
            // Purge resolved incidents older than the configured grace
            $days = (int) $cfg->get('incident_retention_days', 90);
            if ($days > 0 && mt_rand(1, 288) == 11) {
                Incident::objects()->filter(array(
                    'status' => Incident::STATUS_RESOLVED,
                    'resolved__lt' => SqlFunction::DATE_SUB(SqlFunction::NOW(), SqlInterval::DAY($days)),
                ))->delete();
            }
        }
        catch (Throwable $t) {
            ConnectorManager::logError('incidents.cron', $t);
        }
    }

    static function normalizeSeverity($value) {
        $value = strtolower(trim((string) $value));
        $map = array(
            'crit' => 'critical', 'critical' => 'critical', 'fatal' => 'critical', 'emergency' => 'critical',
            'disaster' => 'critical', 'p1' => 'critical', 'sev1' => 'critical', 'down' => 'critical',
            'err' => 'error', 'error' => 'error', 'high' => 'error', 'major' => 'error', 'p2' => 'error',
            'warn' => 'warning', 'warning' => 'warning', 'medium' => 'warning', 'minor' => 'warning', 'p3' => 'warning',
            'degraded' => 'warning',
            'info' => 'info', 'information' => 'info', 'low' => 'info', 'notice' => 'info', 'p4' => 'info',
            'ok' => 'info', 'debug' => 'info',
        );
        return isset($map[$value]) ? $map[$value] : Incident::SEV_WARNING;
    }

    static function normalizeStatus($value) {
        $value = strtolower(trim((string) $value));
        if (in_array($value, array('resolved', 'ok', 'recovered', 'closed', 'up', 'clear', 'cleared', 'recovery')))
            return Incident::STATUS_RESOLVED;
        if (in_array($value, array('acknowledged', 'ack')))
            return Incident::STATUS_ACKNOWLEDGED;
        return Incident::STATUS_OPEN;
    }

    static function resolveDept($data) {
        global $cfg;
        $dept = null;
        if (!empty($data['dept_id']))
            $dept = Dept::lookup((int) $data['dept_id']);
        if (!$dept && !empty($data['department'])) {
            if (is_numeric($data['department']))
                $dept = Dept::lookup((int) $data['department']);
            elseif (($id = Dept::getIdByName($data['department'])))
                $dept = Dept::lookup($id);
        }
        if (!$dept && ($id = (int) $cfg->get('incident_dept_id')))
            $dept = Dept::lookup($id);
        if (!$dept)
            $dept = $cfg->getDefaultDept();
        return $dept;
    }

    /**
     * Ingest a monitoring event. Returns the incident (created or
     * updated) or null with $errors populated.
     *
     * $event keys: source*, title*, detail|message, severity, status,
     *   key|dedupe_key|external_id, url, department|dept_id, tags[],
     *   create_ticket, extra{}
     */
    static function ingest(array $event, &$errors, $apiKeyId=0) {
        global $cfg;

        $source = trim((string) (isset($event['source']) ? $event['source'] : ''));
        $title = trim((string) (isset($event['title']) ? $event['title'] : ''));
        if ($source === '')
            $errors['source'] = __('Source (system name) is required');
        if ($title === '')
            $errors['title'] = __('Title is required');
        if ($errors)
            return null;

        $detail = isset($event['detail']) ? $event['detail']
            : (isset($event['message']) ? $event['message'] : '');
        if ($detail instanceof ThreadEntryBody)
            $detail = $detail->getClean();
        $detail = trim((string) $detail);

        $severity = self::normalizeSeverity(isset($event['severity']) ? $event['severity'] : 'warning');
        $status = self::normalizeStatus(isset($event['status']) ? $event['status'] : 'open');
        $key = '';
        foreach (array('key', 'dedupe_key', 'external_id', 'id') as $k)
            if (!empty($event[$k])) { $key = (string) $event[$k]; break; }
        if ($key === '')
            $key = substr(md5(strtolower($source . '|' . $title)), 0, 32);
        $key = mb_substr($key, 0, 150);
        $url = isset($event['url']) && Validator::is_url($event['url']) ? $event['url'] : null;
        $tags = isset($event['tags']) && is_array($event['tags'])
            ? array_values(array_map('strval', $event['tags'])) : array();
        $extra = isset($event['extra']) && is_array($event['extra']) ? $event['extra'] : array();
        if (!empty($event['occurred_at']))
            $extra['occurred_at'] = (string) $event['occurred_at'];

        $incident = Incident::lookupByKey(mb_substr($source, 0, 128), $key);

        // ---- Recovery / resolution ----
        if ($status == Incident::STATUS_RESOLVED) {
            if (!$incident) {
                // Record resolved-only events for the timeline without
                // opening tickets
                $incident = Incident::create(array(
                    'source' => mb_substr($source, 0, 128),
                    'external_id' => $key,
                    'severity' => $severity,
                    'status' => Incident::STATUS_RESOLVED,
                    'title' => mb_substr($title, 0, 255),
                    'detail' => $detail,
                    'url' => $url,
                    'dept_id' => ($d = self::resolveDept($event)) ? $d->getId() : 0,
                    'api_key_id' => (int) $apiKeyId,
                    'occurrences' => 1,
                    'first_seen' => SqlFunction::NOW(),
                    'last_seen' => SqlFunction::NOW(),
                    'resolved' => SqlFunction::NOW(),
                ));
                $incident->setExtra(array_merge($extra, array('tags' => $tags)));
                $incident->save(true);
                return $incident;
            }
            $incident->last_seen = SqlFunction::NOW();
            $incident->save(true);
            $incident->resolve(null, $detail ?: __('Recovery notification received'));
            return $incident;
        }

        // ---- Existing open incident: fold the occurrence in ----
        if ($incident && $incident->isOpen()) {
            $escalated = Incident::severityRank($severity) > Incident::severityRank($incident->getSeverity());
            $incident->occurrences = $incident->getOccurrences() + 1;
            $incident->last_seen = SqlFunction::NOW();
            $incident->updated = SqlFunction::NOW();
            if ($escalated)
                $incident->severity = $severity;
            if ($detail)
                $incident->detail = $detail;
            if ($url)
                $incident->url = $url;
            $e = $incident->getExtra();
            $e['tags'] = array_values(array_unique(array_merge(isset($e['tags']) ? $e['tags'] : array(), $tags)));
            $e['last_payload'] = $extra;
            $incident->setExtra($e);
            $incident->save(true);

            $ticket = $incident->getTicket();
            $renotify = (int) $cfg->get('incident_renotify_minutes', 60);
            $last = isset($e['notified']) ? (int) $e['notified'] : 0;
            $occ = $incident->getOccurrences();
            $milestone = in_array($occ, array(2, 5, 10, 25, 50, 100)) || $occ % 100 == 0;
            if ($ticket && ($escalated || $milestone)) {
                $ticket->logNote(
                    $escalated ? sprintf(__('Incident escalated to %s'), $incident->getSeverityLabel())
                        : sprintf(__('Incident repeated (%d occurrences)'), $occ),
                    $detail ?: $title, 'Monitor', $escalated);
                if ($escalated && ($pid = self::priorityFor($severity)))
                    self::setTicketPriority($ticket, $pid);
                if ($ticket->isClosed() && $ticket->isReopenable())
                    $ticket->reopen();
            }
            if ($escalated || !$last || ($renotify > 0 && (time() - $last) >= $renotify * 60)) {
                self::notify($incident, $escalated ? 'escalated' : 'repeated',
                    sprintf(__('%d occurrences since %s'), $occ, Format::datetime($incident->getFirstSeen())));
            }
            return $incident;
        }

        // ---- Resolved incident fired again: reopen as a new occurrence ----
        if ($incident && $incident->isResolved()) {
            $incident->status = Incident::STATUS_OPEN;
            $incident->resolved = null;
            $incident->acknowledged = null;
            $incident->severity = $severity;
            $incident->occurrences = $incident->getOccurrences() + 1;
            $incident->first_seen = SqlFunction::NOW();
            $incident->last_seen = SqlFunction::NOW();
            $incident->updated = SqlFunction::NOW();
            if ($detail)
                $incident->detail = $detail;
            $incident->save(true);
            if (($ticket = $incident->getTicket()) && $ticket->isClosed() && $ticket->isReopenable()) {
                $ticket->reopen();
                $ticket->logNote(__('Incident reopened'), $detail ?: $title, 'Monitor', true);
            }
            elseif (!$ticket || !$ticket->isOpen())
                self::openTicket($incident, $event);
            self::notify($incident, 'reopened', $detail);
            return $incident;
        }

        // ---- Brand new incident ----
        $dept = self::resolveDept($event);
        $incident = Incident::create(array(
            'source' => mb_substr($source, 0, 128),
            'external_id' => $key,
            'severity' => $severity,
            'status' => $status,
            'title' => mb_substr($title, 0, 255),
            'detail' => $detail,
            'url' => $url,
            'dept_id' => $dept ? $dept->getId() : 0,
            'api_key_id' => (int) $apiKeyId,
            'occurrences' => 1,
            'first_seen' => SqlFunction::NOW(),
            'last_seen' => SqlFunction::NOW(),
        ));
        if ($status == Incident::STATUS_ACKNOWLEDGED)
            $incident->acknowledged = SqlFunction::NOW();
        $incident->setExtra(array_merge($extra, array('tags' => $tags)));
        if (!$incident->save(true)) {
            $errors['err'] = __('Unable to record the incident');
            return null;
        }

        $createTicket = isset($event['create_ticket'])
            ? (bool) $event['create_ticket']
            : (bool) $cfg->get('incident_create_ticket', 1);
        $minSev = $cfg->get('incident_ticket_min_severity', Incident::SEV_WARNING);
        if ($createTicket && Incident::severityRank($severity) >= Incident::severityRank($minSev))
            self::openTicket($incident, $event);

        self::notify($incident, 'open', $detail);
        return $incident;
    }

    static function priorityFor($severity) {
        $map = array(Incident::SEV_CRITICAL => 1, Incident::SEV_ERROR => 2,
            Incident::SEV_WARNING => 3, Incident::SEV_INFO => 4);
        $urgency = isset($map[$severity]) ? $map[$severity] : 3;
        return ConnectorManager::priorityId($urgency);
    }

    static function setTicketPriority(Ticket $ticket, $priorityId) {
        foreach (DynamicFormEntry::forTicket($ticket->getId()) as $form) {
            if ($form->getAnswer('priority')) {
                $form->setAnswer('priority', null, $priorityId);
                $form->save();
            }
        }
        $ticket->loadDynamicData(true);
    }

    /**
     * Open a ticket for the incident in the mapped department
     */
    static function openTicket(Incident $incident, array $event=array()) {
        global $cfg;

        $dept = $incident->getDept() ?: $cfg->getDefaultDept();
        $email = $cfg->get('incident_reporter_email') ?: self::defaultReporterEmail();
        if (!empty($event['email']) && Validator::is_email($event['email']))
            $email = $event['email'];

        $lines = array();
        $lines[] = sprintf(__('System: %s'), $incident->getSource());
        $lines[] = sprintf(__('Severity: %s'), $incident->getSeverityLabel());
        $lines[] = sprintf(__('Incident key: %s'), $incident->getExternalId());
        if ($incident->getUrl())
            $lines[] = sprintf(__('Link: %s'), $incident->getUrl());
        if (($tags = $incident->getTags()))
            $lines[] = sprintf(__('Tags: %s'), implode(', ', $tags));
        $lines[] = '';
        $lines[] = $incident->getDetail() ?: $incident->getTitle();
        $extra = $incident->getExtra();
        $payload = array_diff_key($extra, array('tags' => 1, 'notified' => 1, 'last_payload' => 1));
        if ($payload) {
            $lines[] = '';
            $lines[] = __('Details:');
            foreach ($payload as $k => $v)
                $lines[] = sprintf('  %s: %s', $k, is_scalar($v) ? $v : json_encode($v));
        }

        $vars = array(
            'name' => !empty($event['name']) ? Format::sanitize($event['name']) : $incident->getSource(),
            'email' => $email,
            'subject' => mb_substr(sprintf('[%s] %s', $incident->getSource(), $incident->getTitle()), 0, 255),
            'message' => new TextThreadEntryBody(implode("\n", $lines)),
            'deptId' => $dept ? $dept->getId() : $cfg->getDefaultDeptId(),
            'source' => 'Monitor',
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
        );
        if (($pid = self::priorityFor($incident->getSeverity())))
            $vars['priorityId'] = $pid;
        if (($topic = (int) $cfg->get('incident_topic_id')))
            $vars['topicId'] = $topic;

        $errors = array();
        $ticket = Ticket::create($vars, $errors, 'api', false, true);
        if (!$ticket) {
            global $ost;
            if ($ost)
                $ost->logWarning(__('Incident ticket not created'),
                    sprintf("%s\n%s", $incident->getTitle(), Format::array_implode(': ', "\n", $errors)), false);
            return null;
        }
        $incident->ticket_id = $ticket->getId();
        $incident->save(true);
        return $ticket;
    }

    /**
     * Requester address used for incident tickets when none is configured.
     * System mailboxes cannot open tickets, so derive a monitoring address
     * from the helpdesk's default email domain.
     */
    static function defaultReporterEmail() {
        global $cfg;
        $domain = 'localhost';
        // The default address may be formatted as "Name <user@domain>"
        if ($cfg && ($default = $cfg->getDefaultEmailAddress())
                && preg_match('/@([A-Za-z0-9.\-]+)/', $default, $m))
            $domain = $m[1];
        return 'monitoring@' . $domain;
    }

    static function alertFor(Incident $incident, $event, $note='') {
        $ticket = $incident->getTicket();
        $facts = array(
            array(__('System'), $incident->getSource()),
            array(__('Severity'), $incident->getSeverityLabel()),
            array(__('Status'), $incident->getStatusLabel()),
            array(__('Occurrences'), (string) $incident->getOccurrences()),
            array(__('First seen'), Format::datetime($incident->getFirstSeen())),
        );
        if ($incident->getDeptName())
            $facts[] = array(__('Department'), $incident->getDeptName());
        if ($ticket)
            $facts[] = array(__('Ticket'), '#' . $ticket->getNumber());
        if (($tags = $incident->getTags()))
            $facts[] = array(__('Tags'), implode(', ', $tags));

        $titles = array(
            'open' => __('Incident: %s'),
            'escalated' => __('Incident escalated: %s'),
            'repeated' => __('Incident still firing: %s'),
            'reopened' => __('Incident reopened: %s'),
            'acknowledged' => __('Incident acknowledged: %s'),
            'resolved' => __('Incident resolved: %s'),
        );
        $text = $incident->getDetail() ?: $incident->getTitle();
        if ($note && $event != 'open')
            $text = $note . "\n\n" . $text;
        if ($incident->getUrl())
            $text .= "\n" . $incident->getUrl();

        return array(
            'kind' => 'incident',
            'event' => $event,
            'severity' => $incident->getSeverity(),
            'title' => sprintf(isset($titles[$event]) ? $titles[$event] : '%s', $incident->getTitle()),
            'text' => $text,
            'facts' => $facts,
            'url' => $ticket ? sprintf('%s/scp/tickets.php?id=%d',
                rtrim(ConnectorManager::baseUrl(), '/'), $ticket->getId()) : $incident->getStaffUrl(),
            'incident_id' => $incident->getId(),
            'ticket_id' => $ticket ? $ticket->getId() : null,
        );
    }

    /**
     * Push the incident to the alert channels of its department
     */
    static function notify(Incident $incident, $event, $note='') {
        $alert = self::alertFor($incident, $event, $note);
        $n = ConnectorManager::notifyDept($incident->getDeptId(), $alert);
        $e = $incident->getExtra();
        $e['notified'] = time();
        $incident->setExtra($e);
        $incident->save(true);
        return $n;
    }

    static function getSummary() {
        return Incident::getCounts();
    }
}
?>
