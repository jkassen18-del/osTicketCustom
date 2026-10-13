<?php
/*********************************************************************
    api.monitor.php

    Monitoring / system reporting API.

        POST /api/monitor/events.json     report an event (or {"events": [...]})
        GET  /api/monitor/incidents.json  list incidents

    Requires an API key with the "Can report incidents" scope.

    Example payload:

        {"source": "payments-api", "title": "Error rate above 5%",
         "severity": "critical", "status": "firing",
         "detail": "502 responses from upstream for the last 5 minutes",
         "key": "payments-api/error-rate", "url": "https://grafana/…",
         "department": "Engineering", "tags": ["prod", "eu-west"]}

    Send the same key with "status": "resolved" to close the incident
    (and the linked ticket) when the system recovers.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.incident.php';

class MonitorApiController extends ApiController {

    static $eventFields = array(
        'source', 'title', 'detail', 'message', 'severity', 'status', 'key',
        'dedupe_key', 'external_id', 'id', 'url', 'department', 'dept_id',
        'occurred_at', 'create_ticket', 'name', 'email',
        'tags' => array('*' => '*'),
        'extra' => array('*' => '*'),
    );

    function getRequestStructure($format, $data=null) {
        $fields = self::$eventFields;
        $fields['events'] = array('*' => self::$eventFields);
        return $fields;
    }

    function report($format) {
        global $ost;

        if (!($key = $this->requireApiKey()) || !$key->canReportIncidents())
            return $this->exerr(401, __('API key not authorized to report incidents'));
        if ($ost && $ost->isUpgradePending())
            return $this->exerr(503, __('System upgrade is pending'));

        $data = $this->getRequest($format);
        $events = isset($data['events']) && is_array($data['events']) ? $data['events'] : array($data);

        $results = array();
        $failed = 0;
        foreach ($events as $event) {
            $errors = array();
            if (!is_array($event)) {
                $failed++;
                $results[] = array('ok' => false, 'errors' => array('err' => 'Invalid event'));
                continue;
            }
            $incident = IncidentManager::ingest($event, $errors, $key->getId());
            if (!$incident) {
                $failed++;
                $results[] = array('ok' => false, 'errors' => $errors);
                continue;
            }
            $results[] = $this->incidentSummary($incident);
        }

        $code = $failed ? ($failed == count($events) ? 422 : 207) : 201;
        $this->emit($format, $code, array(
            'ok' => $failed == 0,
            'received' => count($events),
            'failed' => $failed,
            'results' => $results,
        ));
    }

    function search($format) {
        if (!($key = $this->requireApiKey())
                || !($key->canReportIncidents() || $key->canReadTickets()))
            return $this->exerr(401, __('API key not authorized'));

        $q = Incident::objects();
        if (!empty($_GET['status']) && $_GET['status'] != 'all') {
            $status = IncidentManager::normalizeStatus($_GET['status']);
            if ($_GET['status'] == 'active')
                $q = $q->filter(array('status__in' => array(Incident::STATUS_OPEN, Incident::STATUS_ACKNOWLEDGED)));
            else
                $q = $q->filter(array('status' => $status));
        }
        if (!empty($_GET['severity']))
            $q = $q->filter(array('severity' => IncidentManager::normalizeSeverity($_GET['severity'])));
        if (!empty($_GET['source']))
            $q = $q->filter(array('source' => $_GET['source']));
        $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 50;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $q = $q->order_by('-last_seen')->limit($limit)->offset(($page - 1) * $limit);

        $list = array();
        foreach ($q as $incident)
            $list[] = $this->incidentSummary($incident);
        $this->emit($format, 200, array('ok' => true, 'page' => $page, 'limit' => $limit,
            'incidents' => $list));
    }
    /**
     * Database timestamps as strings; values just written with NOW() and
     * not yet refetched are rendered as the current database time.
     */
    static function dateString($value) {
        if ($value instanceof SqlFunction)
            return date('Y-m-d H:i:s', Misc::dbtime());
        return $value === null ? '' : (string) $value;
    }


    protected function incidentSummary(Incident $incident) {
        global $cfg;
        $ticket = $incident->getTicket();
        $links = array();
        foreach ($incident->getLinks() as $l)
            $links[] = array('connector' => (string) $l->getConnector(), 'key' => $l->getExternalKey(),
                'url' => $l->getExternalUrl());
        return array(
            'ok' => true,
            'incident_id' => $incident->getId(),
            'source' => $incident->getSource(),
            'key' => $incident->getExternalId(),
            'title' => $incident->getTitle(),
            'severity' => $incident->getSeverity(),
            'status' => $incident->getStatus(),
            'occurrences' => $incident->getOccurrences(),
            'department' => $incident->getDeptName(),
            'first_seen' => self::dateString($incident->getFirstSeen()),
            'last_seen' => self::dateString($incident->getLastSeen()),
            'resolved' => self::dateString($incident->getResolvedDate()),
            'ticket' => $ticket ? array(
                'id' => $ticket->getId(),
                'number' => $ticket->getNumber(),
                'status' => (string) $ticket->getStatus(),
                'url' => sprintf('%s/scp/tickets.php?id=%d', rtrim($cfg->getBaseUrl(), '/'), $ticket->getId()),
            ) : null,
            'links' => $links,
            'url' => $incident->getStaffUrl(),
        );
    }

    protected function emit($format, $code, array $data) {
        if (strcasecmp($format, 'xml') === 0) {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . ApiXmlOutput::encode($data, 'response');
            Http::response($code, $xml, 'application/xml');
        }
        else {
            Http::response($code, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'application/json');
        }
        exit();
    }
}

if (!class_exists('ApiXmlOutput')) {
    /**
     * Tiny array → XML serializer for API responses
     */
    class ApiXmlOutput {
        static function encode($data, $root='response') {
            return sprintf('<%1$s>%2$s</%1$s>', $root, self::body($data));
        }

        static function body($data) {
            $out = '';
            foreach ((array) $data as $k => $v) {
                $tag = is_numeric($k) ? 'item' : preg_replace('/[^a-z0-9_]/i', '_', $k);
                if (is_array($v))
                    $out .= sprintf('<%1$s>%2$s</%1$s>', $tag, self::body($v));
                elseif ($v === null)
                    $out .= sprintf('<%s/>', $tag);
                else
                    $out .= sprintf('<%1$s>%2$s</%1$s>', $tag,
                        htmlspecialchars(is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }
            return $out;
        }
    }
}
?>
