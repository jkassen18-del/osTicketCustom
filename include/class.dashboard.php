<?php
/*********************************************************************
    class.dashboard.php

    Dashboard metrics and the report builder.

    DashboardReport  - KPIs, activity series, breakdowns, workload, SLA
                       compliance, personal work list, integration health
                       and per-agent widget preferences
    ReportBuilder    - configurable reports (metric × period × split with
                       filters) rendered as charts and tables, CSV export
                       and saved presets

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

require_once INCLUDE_DIR.'class.report.php';
require_once INCLUDE_DIR.'class.ticket.php';
require_once INCLUDE_DIR.'class.sla_escalation.php';
require_once INCLUDE_DIR.'class.connector.php';
require_once INCLUDE_DIR.'class.incident.php';

class DashboardReport {
    var $staff;
    var $start;
    var $period;
    var $range;         // array(startExpr, stopExpr) FROM_UNIXTIME(...)
    var $startTs;       // db timestamps
    var $stopTs;
    var $depts;         // visible department ids
    var $overview;

    static $widgets = array(
        'kpis' => /* @trans */ 'Key numbers',
        'activity' => /* @trans */ 'Ticket activity',
        'load' => /* @trans */ 'Workload by department',
        'breakdown' => /* @trans */ 'Backlog breakdown',
        'sla' => /* @trans */ 'SLA compliance',
        'mywork' => /* @trans */ 'My work',
        'recent' => /* @trans */ 'Recent activity',
        'health' => /* @trans */ 'Integrations and incidents',
        'stats' => /* @trans */ 'Statistics table',
    );

    static $breakdowns = array(
        'status' => /* @trans */ 'Status',
        'priority' => /* @trans */ 'Priority',
        'dept' => /* @trans */ 'Department',
        'topic' => /* @trans */ 'Help topic',
        'staff' => /* @trans */ 'Agent',
        'source' => /* @trans */ 'Source',
    );

    function __construct(Staff $staff, $start=null, $period=null) {
        $this->staff = $staff;
        $this->overview = new OverviewReport($start, $period ?: 'now');
        $this->start = $start;
        $this->period = $this->overview->end;
        $this->range = $this->overview->getDateRange();
        $this->startTs = (int) preg_replace('/\D/', '', $this->range[0]);
        $this->stopTs = (int) preg_replace('/\D/', '', $this->range[1]);
        $this->depts = $staff->getDepts() ?: array();
    }

    function getOverview() {
        return $this->overview;
    }

    function getRangeLabel() {
        global $cfg;
        $tz = new DateTimeZone($cfg->getTimezone());
        $labels = array();
        foreach (array($this->startTs, $this->stopTs) as $ts) {
            $d = new DateTime('@'.$ts);
            $d->setTimezone($tz);
            $labels[] = $d->format('M j, Y');
        }
        return implode(' – ', $labels);
    }

    protected function deptSql($alias='T') {
        if (!$this->depts)
            return ' AND 0';
        return sprintf(' AND %s.dept_id IN (%s)', $alias, implode(',', array_map('intval', $this->depts)));
    }

    protected function scalar($sql) {
        if (($res = db_query($sql)) && ($row = db_fetch_row($res)))
            return $row[0];
        return 0;
    }

    /* ------------------------- KPIs ------------------------- */

    function getKpis() {
        global $cfg;
        $T = TICKET_TABLE;
        $S = TICKET_STATUS_TABLE;
        $open = sprintf('SELECT COUNT(*) FROM %s T JOIN %s S ON S.id = T.status_id WHERE S.state = "open"%s',
            $T, $S, $this->deptSql());
        $kpis = array();
        $kpis['open'] = (int) $this->scalar($open);
        $kpis['unassigned'] = (int) $this->scalar($open . ' AND T.staff_id = 0 AND T.team_id = 0');
        $kpis['overdue'] = (int) $this->scalar($open . ' AND T.isoverdue = 1');
        $kpis['awaiting'] = (int) $this->scalar($open . ' AND T.isanswered = 0');
        $kpis['due_today'] = (int) $this->scalar($open
            . ' AND T.isoverdue = 0 AND COALESCE(T.duedate, T.est_duedate) IS NOT NULL'
            . ' AND COALESCE(T.duedate, T.est_duedate) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR)');
        $kpis['mine'] = (int) $this->scalar(sprintf(
            'SELECT COUNT(*) FROM %s T JOIN %s S ON S.id = T.status_id WHERE S.state = "open" AND T.staff_id = %d',
            $T, $S, $this->staff->getId()));
        $kpis['created'] = (int) $this->scalar(sprintf(
            'SELECT COUNT(*) FROM %s T WHERE T.created BETWEEN %s AND %s%s',
            $T, $this->range[0], $this->range[1], $this->deptSql()));
        $kpis['closed'] = (int) $this->scalar(sprintf(
            'SELECT COUNT(*) FROM %s T WHERE T.closed BETWEEN %s AND %s%s',
            $T, $this->range[0], $this->range[1], $this->deptSql()));
        $kpis['response_hours'] = $this->avgResponseHours();
        $kpis['service_hours'] = $this->avgServiceHours();
        $kpis['sla'] = SLAEscalator::compliance($this->range[0], $this->range[1], $this->depts);
        $kpis['at_risk'] = (int) SLAEvent::countSince(SLAEvent::TYPE_WARNING,
            SqlFunction::DATE_SUB(SqlFunction::NOW(), SqlInterval::DAY(7)));
        return $kpis;
    }

    function avgResponseHours() {
        $sql = sprintf('SELECT AVG(TIMESTAMPDIFF(MINUTE, T.created, R.first)) FROM %s T'
            . ' JOIN %s TH ON TH.object_id = T.ticket_id AND TH.object_type = "T"'
            . ' JOIN (SELECT thread_id, MIN(created) AS first FROM %s WHERE type = "R" GROUP BY thread_id) R ON R.thread_id = TH.id'
            . ' WHERE T.created BETWEEN %s AND %s%s',
            TICKET_TABLE, THREAD_TABLE, THREAD_ENTRY_TABLE, $this->range[0], $this->range[1], $this->deptSql());
        $v = $this->scalar($sql);
        return $v === null || $v === '' ? null : round($v / 60, 1);
    }

    function avgServiceHours() {
        $sql = sprintf('SELECT AVG(TIMESTAMPDIFF(MINUTE, T.created, T.closed)) FROM %s T'
            . ' WHERE T.closed BETWEEN %s AND %s%s',
            TICKET_TABLE, $this->range[0], $this->range[1], $this->deptSql());
        $v = $this->scalar($sql);
        return $v === null || $v === '' ? null : round($v / 60, 1);
    }

    /* ------------------------- Series ------------------------- */

    /**
     * Daily activity series (created / closed / reopened / overdue / …)
     * in a chart-friendly structure.
     */
    function getActivitySeries() {
        $plot = $this->overview->getPlotData();
        $labels = array();
        foreach ($plot['times'] as $ts)
            $labels[] = date('Y-m-d', $ts);
        $series = array();
        foreach ($plot['plots'] as $name => $values)
            $series[] = array('name' => $name, 'values' => array_map('intval', $values));
        return array('labels' => $labels, 'series' => $series);
    }

    /* ------------------------- Breakdowns ------------------------- */

    /**
     * Backlog (open tickets) grouped by status / priority / department /
     * topic / agent / source
     */
    function getBreakdown($by='status', $limit=8) {
        $T = TICKET_TABLE;
        $S = TICKET_STATUS_TABLE;
        $base = sprintf('FROM %s T JOIN %s S ON S.id = T.status_id', $T, $S);
        $where = ' WHERE S.state = "open"' . $this->deptSql();
        switch ($by) {
        case 'priority':
            $sql = sprintf('SELECT P.priority_desc, COUNT(*) %s LEFT JOIN %s C ON C.ticket_id = T.ticket_id'
                . ' LEFT JOIN %s P ON P.priority_id = C.priority %s GROUP BY P.priority_desc ORDER BY P.priority_urgency',
                $base, TICKET_CDATA_TABLE, PRIORITY_TABLE, $where);
            break;
        case 'dept':
            $sql = sprintf('SELECT D.name, COUNT(*) %s LEFT JOIN %s D ON D.id = T.dept_id %s GROUP BY D.name ORDER BY 2 DESC',
                $base, DEPT_TABLE, $where);
            break;
        case 'topic':
            $sql = sprintf('SELECT H.topic, COUNT(*) %s LEFT JOIN %s H ON H.topic_id = T.topic_id %s GROUP BY H.topic ORDER BY 2 DESC',
                $base, TOPIC_TABLE, $where);
            break;
        case 'staff':
            $sql = sprintf('SELECT CONCAT_WS(" ", A.firstname, A.lastname), COUNT(*) %s LEFT JOIN %s A ON A.staff_id = T.staff_id %s GROUP BY 1 ORDER BY 2 DESC',
                $base, STAFF_TABLE, $where);
            break;
        case 'source':
            $sql = sprintf('SELECT T.source, COUNT(*) %s %s GROUP BY T.source ORDER BY 2 DESC', $base, $where);
            break;
        default:
            $by = 'status';
            $sql = sprintf('SELECT S.name, COUNT(*) %s %s GROUP BY S.name ORDER BY 2 DESC', $base, $where);
        }
        $items = array();
        $other = 0;
        if (($res = db_query($sql))) {
            while ($row = db_fetch_row($res)) {
                $label = $row[0] !== null && $row[0] !== '' ? $row[0] : __('Unassigned');
                if ($by == 'staff' && ($row[0] === null || trim($row[0]) === ''))
                    $label = __('Unassigned');
                if (count($items) < $limit)
                    $items[] = array('label' => $label, 'value' => (int) $row[1]);
                else
                    $other += (int) $row[1];
            }
        }
        if ($other)
            $items[] = array('label' => __('Other'), 'value' => $other);
        return array('by' => $by, 'items' => $items);
    }

    /**
     * Open / unassigned / overdue counts per department
     */
    function getWorkload() {
        $sql = sprintf('SELECT T.dept_id, D.name, COUNT(*), SUM(T.staff_id = 0 AND T.team_id = 0), SUM(T.isoverdue = 1), SUM(T.isanswered = 0)'
            . ' FROM %s T JOIN %s S ON S.id = T.status_id LEFT JOIN %s D ON D.id = T.dept_id'
            . ' WHERE S.state = "open"%s GROUP BY T.dept_id, D.name ORDER BY 3 DESC',
            TICKET_TABLE, TICKET_STATUS_TABLE, DEPT_TABLE, $this->deptSql());
        $rows = array();
        if (($res = db_query($sql))) {
            while ($row = db_fetch_row($res)) {
                $rows[] = array(
                    'dept_id' => (int) $row[0],
                    'name' => $row[1] ?: __('Unknown'),
                    'open' => (int) $row[2],
                    'unassigned' => (int) $row[3],
                    'overdue' => (int) $row[4],
                    'awaiting' => (int) $row[5],
                );
            }
        }
        return $rows;
    }

    /* ------------------------- Lists ------------------------- */

    function getMyWork($limit=8) {
        return Ticket::objects()
            ->filter(array('staff_id' => $this->staff->getId(), 'status__state' => 'open'))
            ->order_by('-isoverdue', 'est_duedate', '-lastupdate')
            ->limit($limit);
    }

    function getRecentActivity($limit=8) {
        if (!$this->depts)
            return array();
        $sql = sprintf('SELECT E.id FROM %s E JOIN %s TH ON TH.id = E.thread_id AND TH.object_type = "T"'
            . ' JOIN %s T ON T.ticket_id = TH.object_id WHERE E.type IN ("M","R","N")%s ORDER BY E.id DESC LIMIT %d',
            THREAD_ENTRY_TABLE, THREAD_TABLE, TICKET_TABLE, $this->deptSql(), (int) $limit);
        $ids = array();
        if (($res = db_query($sql)))
            while ($row = db_fetch_row($res))
                $ids[] = (int) $row[0];
        if (!$ids)
            return array();
        $entries = array();
        foreach (ThreadEntry::objects()->filter(array('id__in' => $ids))->order_by('-id') as $e) {
            $thread = $e->getThread();
            $ticket = $thread ? $thread->getObject() : null;
            if (!$ticket instanceof Ticket)
                continue;
            $entries[] = array('entry' => $e, 'ticket' => $ticket);
        }
        return $entries;
    }

    function getIntegrationHealth() {
        return array(
            'connectors' => ConnectorManager::getHealth(),
            'incidents' => Incident::getCounts(),
            'pending_jobs' => ConnectorJob::countPending(),
        );
    }

    /* ------------------------- Preferences ------------------------- */

    function getPrefs() {
        $config = new Config('dashboard.staff.'.$this->staff->getId());
        $prefs = array(
            'widgets' => array_keys(self::$widgets),
            'chart' => 'line',
            'breakdown' => 'status',
            'period' => '+1 month',
        );
        if (($json = $config->get('prefs')) && ($saved = json_decode($json, true)) && is_array($saved)) {
            $prefs = array_merge($prefs, $saved);
            if (!is_array($prefs['widgets']))
                $prefs['widgets'] = array_keys(self::$widgets);
        }
        return $prefs;
    }

    function savePrefs(array $vars) {
        $prefs = $this->getPrefs();
        if (isset($vars['widgets']) && is_array($vars['widgets']))
            $prefs['widgets'] = array_values(array_intersect($vars['widgets'], array_keys(self::$widgets)));
        if (isset($vars['chart']) && in_array($vars['chart'], array('line', 'bar', 'area')))
            $prefs['chart'] = $vars['chart'];
        if (isset($vars['breakdown']) && isset(self::$breakdowns[$vars['breakdown']]))
            $prefs['breakdown'] = $vars['breakdown'];
        if (isset($vars['period']) && array_key_exists($vars['period'], OverviewReport::$end_choices))
            $prefs['period'] = $vars['period'];
        $config = new Config('dashboard.staff.'.$this->staff->getId());
        return $config->set('prefs', json_encode($prefs)) !== null;
    }
}

/**
 * Configurable reports
 */
class ReportBuilder {
    var $staff;
    var $params;
    var $depts;

    static $metrics = array(
        'created' => /* @trans */ 'Tickets created',
        'closed' => /* @trans */ 'Tickets closed',
        'reopened' => /* @trans */ 'Tickets reopened',
        'overdue' => /* @trans */ 'Tickets marked overdue',
        'assigned' => /* @trans */ 'Assignments',
        'transferred' => /* @trans */ 'Transfers',
        'response' => /* @trans */ 'Average first response (hours)',
        'service' => /* @trans */ 'Average time to close (hours)',
        'sla' => /* @trans */ 'SLA compliance (%)',
        'backlog' => /* @trans */ 'Open tickets right now',
    );

    static $periods = array(
        'day' => /* @trans */ 'Day',
        'week' => /* @trans */ 'Week',
        'month' => /* @trans */ 'Month',
    );

    static $splits = array(
        '' => /* @trans */ 'No split',
        'dept' => /* @trans */ 'Department',
        'topic' => /* @trans */ 'Help topic',
        'staff' => /* @trans */ 'Agent',
        'priority' => /* @trans */ 'Priority',
        'source' => /* @trans */ 'Source',
        'status' => /* @trans */ 'Status',
        'sla' => /* @trans */ 'SLA plan',
    );

    static $charts = array(
        'line' => /* @trans */ 'Line',
        'area' => /* @trans */ 'Area',
        'bar' => /* @trans */ 'Bars',
        'stacked' => /* @trans */ 'Stacked bars',
        'donut' => /* @trans */ 'Donut (totals)',
    );

    function __construct(Staff $staff, array $params=array()) {
        $this->staff = $staff;
        $this->params = self::normalize($params);
        $this->depts = $staff->getDepts() ?: array();
    }

    static function normalize(array $p) {
        $out = array(
            'metric' => isset(self::$metrics[$p['metric'] ?? '']) ? $p['metric'] : 'created',
            'period' => isset(self::$periods[$p['period'] ?? '']) ? $p['period'] : 'day',
            'split' => isset(self::$splits[$p['split'] ?? '']) ? $p['split'] : '',
            'chart' => isset(self::$charts[$p['chart'] ?? '']) ? $p['chart'] : 'line',
            'from' => isset($p['from']) ? trim((string) $p['from']) : '',
            'to' => isset($p['to']) ? trim((string) $p['to']) : '',
            'dept' => isset($p['dept']) ? array_values(array_filter(array_map('intval', (array) $p['dept']))) : array(),
            'staff_id' => isset($p['staff_id']) ? (int) $p['staff_id'] : 0,
            'limit' => isset($p['limit']) ? max(2, min(12, (int) $p['limit'])) : 8,
        );
        if (!$out['from'] || strtotime($out['from']) === false)
            $out['from'] = date('Y-m-d', strtotime('-30 days'));
        if (!$out['to'] || strtotime($out['to']) === false)
            $out['to'] = date('Y-m-d');
        if (strtotime($out['from']) > strtotime($out['to']))
            list($out['from'], $out['to']) = array($out['to'], $out['from']);
        return $out;
    }

    function getParams() {
        return $this->params;
    }

    function getTitle() {
        $p = $this->params;
        $title = __(self::$metrics[$p['metric']]);
        if ($p['split'])
            $title .= ' ' . sprintf(__('by %s'), __(self::$splits[$p['split']]));
        return $title;
    }

    protected function rangeExpr() {
        $start = Misc::dbtime($this->params['from'] . ' 00:00:00');
        $stop = Misc::dbtime($this->params['to'] . ' 23:59:59');
        return array(sprintf('FROM_UNIXTIME(%d)', $start), sprintf('FROM_UNIXTIME(%d)', $stop), $start, $stop);
    }

    protected function bucketExpr($column) {
        switch ($this->params['period']) {
        case 'week':
            return sprintf('DATE_FORMAT(%s, "%%x-W%%v")', $column);
        case 'month':
            return sprintf('DATE_FORMAT(%s, "%%Y-%%m")', $column);
        }
        return sprintf('DATE_FORMAT(%s, "%%Y-%%m-%%d")', $column);
    }

    protected function bucketKey($ts) {
        switch ($this->params['period']) {
        case 'week':
            return date('o-\WW', $ts);
        case 'month':
            return date('Y-m', $ts);
        }
        return date('Y-m-d', $ts);
    }

    protected function allBuckets($startTs, $stopTs) {
        $buckets = array();
        $step = $this->params['period'] == 'month' ? '+1 month' : ($this->params['period'] == 'week' ? '+1 week' : '+1 day');
        $ts = $startTs;
        $guard = 0;
        while ($ts <= $stopTs && $guard++ < 1000) {
            $buckets[$this->bucketKey($ts)] = true;
            $ts = strtotime($step, $ts);
        }
        $buckets[$this->bucketKey($stopTs)] = true;
        return array_keys($buckets);
    }

    /**
     * SQL expression and joins for the split dimension
     */
    protected function splitExpr($alias='T') {
        switch ($this->params['split']) {
        case 'dept':
            return array(sprintf('COALESCE(D.name, "—")', $alias), sprintf(' LEFT JOIN %s D ON D.id = %s.dept_id', DEPT_TABLE, $alias));
        case 'topic':
            return array('COALESCE(H.topic, "—")', sprintf(' LEFT JOIN %s H ON H.topic_id = %s.topic_id', TOPIC_TABLE, $alias));
        case 'staff':
            return array('COALESCE(NULLIF(CONCAT_WS(" ", A.firstname, A.lastname), ""), "Unassigned")',
                sprintf(' LEFT JOIN %s A ON A.staff_id = %s.staff_id', STAFF_TABLE, $alias));
        case 'priority':
            return array('COALESCE(P.priority_desc, "—")',
                sprintf(' LEFT JOIN %s C ON C.ticket_id = %s.ticket_id LEFT JOIN %s P ON P.priority_id = C.priority',
                    TICKET_CDATA_TABLE, $alias, PRIORITY_TABLE));
        case 'source':
            return array(sprintf('%s.source', $alias), '');
        case 'status':
            return array('COALESCE(S2.name, "—")', sprintf(' LEFT JOIN %s S2 ON S2.id = %s.status_id', TICKET_STATUS_TABLE, $alias));
        case 'sla':
            return array('COALESCE(L.name, "None")', sprintf(' LEFT JOIN %s L ON L.id = %s.sla_id', SLA_TABLE, $alias));
        }
        return array('"' . __('All') . '"', '');
    }

    protected function filterSql($alias='T') {
        $sql = '';
        $depts = $this->params['dept'] ? array_intersect($this->params['dept'], $this->depts) : $this->depts;
        if (!$depts)
            return ' AND 0';
        $sql .= sprintf(' AND %s.dept_id IN (%s)', $alias, implode(',', array_map('intval', $depts)));
        if ($this->params['staff_id'])
            $sql .= sprintf(' AND %s.staff_id = %d', $alias, $this->params['staff_id']);
        return $sql;
    }

    /**
     * Run the report. Returns array(labels, series (name => values),
     * table rows, totals, unit, type)
     */
    function run() {
        list($startExpr, $stopExpr, $startTs, $stopTs) = $this->rangeExpr();
        $p = $this->params;
        list($splitSql, $joins) = $this->splitExpr();
        $filter = $this->filterSql();
        $T = TICKET_TABLE;
        $data = array();   // bucket => split => value
        $unit = 'count';

        switch ($p['metric']) {
        case 'created':
        case 'closed':
            $col = $p['metric'] == 'closed' ? 'T.closed' : 'T.created';
            $sql = sprintf('SELECT %s AS b, %s AS s, COUNT(*) FROM %s T%s WHERE %s BETWEEN %s AND %s%s GROUP BY b, s',
                $this->bucketExpr($col), $splitSql, $T, $joins, $col, $startExpr, $stopExpr, $filter);
            $this->collect($sql, $data);
            break;
        case 'reopened':
        case 'overdue':
        case 'assigned':
        case 'transferred':
            $ids = Event::getIds();
            $eid = isset($ids[$p['metric']]) ? (int) $ids[$p['metric']] : 0;
            $sql = sprintf('SELECT %s AS b, %s AS s, COUNT(*) FROM %s E'
                . ' JOIN %s TH ON TH.id = E.thread_id AND TH.object_type = "T"'
                . ' JOIN %s T ON T.ticket_id = TH.object_id%s'
                . ' WHERE E.event_id = %d AND NOT E.annulled AND E.timestamp BETWEEN %s AND %s%s GROUP BY b, s',
                $this->bucketExpr('E.timestamp'), $splitSql, THREAD_EVENT_TABLE, THREAD_TABLE, $T, $joins,
                $eid, $startExpr, $stopExpr, $filter);
            $this->collect($sql, $data);
            break;
        case 'response':
            $unit = 'hours';
            $sql = sprintf('SELECT %s AS b, %s AS s, AVG(TIMESTAMPDIFF(MINUTE, T.created, R.first)) / 60 FROM %s T'
                . ' JOIN %s TH ON TH.object_id = T.ticket_id AND TH.object_type = "T"'
                . ' JOIN (SELECT thread_id, MIN(created) AS first FROM %s WHERE type = "R" GROUP BY thread_id) R ON R.thread_id = TH.id%s'
                . ' WHERE T.created BETWEEN %s AND %s%s GROUP BY b, s',
                $this->bucketExpr('T.created'), $splitSql, $T, THREAD_TABLE, THREAD_ENTRY_TABLE, $joins,
                $startExpr, $stopExpr, $filter);
            $this->collect($sql, $data, 1);
            break;
        case 'service':
            $unit = 'hours';
            $sql = sprintf('SELECT %s AS b, %s AS s, AVG(TIMESTAMPDIFF(MINUTE, T.created, T.closed)) / 60 FROM %s T%s'
                . ' WHERE T.closed BETWEEN %s AND %s%s GROUP BY b, s',
                $this->bucketExpr('T.closed'), $splitSql, $T, $joins, $startExpr, $stopExpr, $filter);
            $this->collect($sql, $data, 1);
            break;
        case 'sla':
            $unit = 'percent';
            $sql = sprintf('SELECT %s AS b, %s AS s, COUNT(*),'
                . ' SUM(T.isoverdue = 1 OR (T.closed IS NOT NULL AND T.est_duedate IS NOT NULL AND T.closed > T.est_duedate))'
                . ' FROM %s T%s WHERE T.created BETWEEN %s AND %s%s GROUP BY b, s',
                $this->bucketExpr('T.created'), $splitSql, $T, $joins, $startExpr, $stopExpr, $filter);
            if (($res = db_query($sql))) {
                while ($row = db_fetch_row($res)) {
                    $total = (int) $row[2];
                    $breached = (int) $row[3];
                    $data[$row[0]][$row[1]] = $total ? round(($total - $breached) / $total * 100, 1) : null;
                }
            }
            break;
        case 'backlog':
            $sql = sprintf('SELECT "now" AS b, %s AS s, COUNT(*) FROM %s T JOIN %s S ON S.id = T.status_id%s'
                . ' WHERE S.state = "open"%s GROUP BY s ORDER BY 3 DESC',
                $splitSql, $T, TICKET_STATUS_TABLE, $joins, $filter);
            $this->collect($sql, $data);
            break;
        }

        // Shape into labels / series
        if ($p['metric'] == 'backlog') {
            $labels = array(__('Now'));
            $buckets = array('now');
        }
        else {
            $buckets = $this->allBuckets($startTs, $stopTs);
            $labels = $buckets;
        }
        $names = array();
        foreach ($data as $b => $splits)
            foreach ($splits as $s => $v)
                $names[$s] = isset($names[$s]) ? $names[$s] + (float) $v : (float) $v;
        // Keep the biggest splits, fold the rest into "Other"
        arsort($names);
        $keep = array_slice(array_keys($names), 0, $p['limit']);
        $fold = array_diff(array_keys($names), $keep);
        $series = array();
        foreach ($keep as $s)
            $series[$s] = array_fill(0, count($buckets), $unit == 'count' ? 0 : null);
        if ($fold)
            $series[__('Other')] = array_fill(0, count($buckets), $unit == 'count' ? 0 : null);
        foreach ($buckets as $i => $b) {
            if (!isset($data[$b]))
                continue;
            foreach ($data[$b] as $s => $v) {
                $key = in_array($s, $keep) ? $s : __('Other');
                if (!isset($series[$key]))
                    continue;
                if ($unit == 'count')
                    $series[$key][$i] += (int) $v;
                else
                    $series[$key][$i] = $series[$key][$i] === null ? ($v === null ? null : round($v, 1))
                        : round(($series[$key][$i] + $v) / 2, 1);
            }
        }

        // Totals per series
        $totals = array();
        foreach ($series as $s => $values) {
            $vals = array_filter($values, function($v) { return $v !== null; });
            if ($unit == 'count')
                $totals[$s] = array_sum($vals);
            else
                $totals[$s] = count($vals) ? round(array_sum($vals) / count($vals), 1) : null;
        }

        return array(
            'title' => $this->getTitle(),
            'metric' => $p['metric'],
            'unit' => $unit,
            'labels' => $labels,
            'series' => $series,
            'totals' => $totals,
            'chart' => $p['chart'],
            'range' => sprintf('%s – %s', $p['from'], $p['to']),
        );
    }

    protected function collect($sql, &$data, $round=null) {
        if (!($res = db_query($sql)))
            return;
        while ($row = db_fetch_row($res)) {
            $v = $row[2];
            if ($round !== null && $v !== null)
                $v = round((float) $v, $round);
            $data[$row[0]][$row[1]] = $v;
        }
    }

    /**
     * Tabular output: one row per bucket, one column per series
     */
    static function toTable(array $result) {
        $columns = array_merge(array(__('Period')), array_keys($result['series']));
        $rows = array();
        foreach ($result['labels'] as $i => $label) {
            $row = array($label);
            foreach ($result['series'] as $values)
                $row[] = $values[$i] === null ? '' : $values[$i];
            $rows[] = $row;
        }
        $totals = array(__('Total'));
        foreach ($result['totals'] as $t)
            $totals[] = $t === null ? '' : $t;
        return array('columns' => $columns, 'rows' => $rows, 'totals' => $totals);
    }

    /* ------------------------- Presets ------------------------- */

    static function getPresets(Staff $staff) {
        $config = new Config('reports.staff.'.$staff->getId());
        $presets = json_decode((string) $config->get('presets'), true);
        return is_array($presets) ? $presets : array();
    }

    static function savePreset(Staff $staff, $name, array $params) {
        $name = trim(Format::sanitize((string) $name));
        if (!$name)
            return false;
        $presets = self::getPresets($staff);
        $presets[$name] = self::normalize($params);
        $config = new Config('reports.staff.'.$staff->getId());
        $config->set('presets', json_encode($presets));
        return true;
    }

    static function deletePreset(Staff $staff, $name) {
        $presets = self::getPresets($staff);
        unset($presets[$name]);
        $config = new Config('reports.staff.'.$staff->getId());
        $config->set('presets', json_encode($presets));
        return true;
    }

    static function defaultPresets() {
        return array(
            __('Volume last 30 days') => array('metric' => 'created', 'period' => 'day', 'split' => 'dept', 'chart' => 'stacked'),
            __('Response time by agent') => array('metric' => 'response', 'period' => 'week', 'split' => 'staff', 'chart' => 'bar'),
            __('SLA compliance by department') => array('metric' => 'sla', 'period' => 'week', 'split' => 'dept', 'chart' => 'line'),
            __('Backlog by priority') => array('metric' => 'backlog', 'period' => 'day', 'split' => 'priority', 'chart' => 'donut'),
            __('Sources this quarter') => array('metric' => 'created', 'period' => 'month', 'split' => 'source', 'chart' => 'stacked',
                'from' => date('Y-m-d', strtotime('-90 days'))),
        );
    }
}
?>
