<?php
/*********************************************************************
    class.connector.php

    Connector framework: bidirectional integrations between osTicket
    departments and external collaboration / issue tracking systems
    (Linear, Microsoft Teams, generic webhooks, ...).

    Every connector maps one or more external "channels" (a Linear team,
    a Teams channel, a webhook routing key) to osTicket departments.
    Tickets created in a channel land in the mapped department and
    ticket activity (new tickets, replies, notes, status changes, SLA
    and incident alerts) is pushed back to the channel(s) mapped to the
    ticket's department.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

require_once INCLUDE_DIR.'class.httpclient.php';

class Connector extends VerySimpleModel {
    static $meta = array(
        'table' => CONNECTOR_TABLE,
        'pk' => array('id'),
        'ordering' => array('name'),
        'joins' => array(
            'channels' => array(
                'reverse' => 'ConnectorChannel.connector',
            ),
        ),
    );

    const FLAG_ACTIVE       = 0x0001;   // Connector enabled
    const FLAG_INBOUND      = 0x0002;   // Accept inbound events (webhooks)
    const FLAG_OUTBOUND     = 0x0004;   // Push ticket activity
    const FLAG_ALERTS       = 0x0008;   // Receive SLA / incident alerts
    const FLAG_SYNC_STATUS  = 0x0010;   // Mirror close / reopen
    const FLAG_PUSH_NOTES   = 0x0020;   // Push internal notes too

    var $_config;
    var $_backend;

    function getId() { return $this->id; }
    function getType() { return $this->type; }
    function getName() { return $this->name; }
    function getNotes() { return $this->notes; }
    function getTypeName() { return ConnectorManager::getTypeName($this->type); }
    function getCreateDate() { return $this->created; }
    function getUpdateDate() { return $this->updated; }
    function getLastRun() { return $this->last_run; }
    function getLastError() { return $this->last_error; }

    function hasFlag($flag) {
        return (((int) $this->flags) & $flag) != 0;
    }

    function setFlag($flag, $val) {
        if ($val)
            $this->flags = ((int) $this->flags) | $flag;
        else
            $this->flags = ((int) $this->flags) & ~$flag;
    }

    function isActive() { return $this->hasFlag(self::FLAG_ACTIVE); }
    function acceptsInbound() { return $this->hasFlag(self::FLAG_INBOUND); }
    function pushesOutbound() { return $this->hasFlag(self::FLAG_OUTBOUND); }
    function receivesAlerts() { return $this->hasFlag(self::FLAG_ALERTS); }
    function syncsStatus() { return $this->hasFlag(self::FLAG_SYNC_STATUS); }
    function pushesNotes() { return $this->hasFlag(self::FLAG_PUSH_NOTES); }

    function getStatus() {
        return $this->isActive() ? __('Active') : __('Disabled');
    }

    function getConfig() {
        if (!isset($this->_config)) {
            $c = $this->config ? json_decode($this->config, true) : null;
            $this->_config = is_array($c) ? $c : array();
        }
        return $this->_config;
    }

    function getSetting($key, $default=null) {
        $c = $this->getConfig();
        if (isset($c[$key]) && $c[$key] !== '' && $c[$key] !== null)
            return $c[$key];
        return $default;
    }

    function setSetting($key, $value) {
        $c = $this->getConfig();
        $c[$key] = $value;
        $this->setConfig($c);
    }

    function setConfig(array $config) {
        $this->_config = $config;
        $this->config = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    function getBackend() {
        if (!isset($this->_backend))
            $this->_backend = ConnectorManager::getBackend($this);
        return $this->_backend;
    }

    function getChannels() {
        return $this->channels;
    }

    function getNumChannels() {
        return $this->channels->count();
    }

    /**
     * Channels of this connector mapped to the department (optionally
     * restricted to channels having $flag set)
     */
    function getChannelsForDept($deptId, $flag=0) {
        $list = array();
        foreach ($this->channels as $ch) {
            if (!$ch->isEnabled())
                continue;
            if ((int) $ch->getDeptId() !== (int) $deptId)
                continue;
            if ($flag && !$ch->hasFlag($flag))
                continue;
            $list[] = $ch;
        }
        return $list;
    }

    function getDefaultChannels($flag=0) {
        $list = array();
        foreach ($this->channels as $ch) {
            if (!$ch->isEnabled() || !$ch->isDefault())
                continue;
            if ($flag && !$ch->hasFlag($flag))
                continue;
            $list[] = $ch;
        }
        return $list;
    }

    function getWebhookUrl() {
        global $cfg;
        return sprintf('%s/api/connectors/%d/%s',
                rtrim($cfg->getBaseUrl(), '/'), $this->getId(), $this->getType());
    }

    function touch($error=null) {
        $this->last_run = SqlFunction::NOW();
        $this->last_error = $error === null ? null : mb_substr((string) $error, 0, 2000);
        return $this->save();
    }

    function logEvent($direction, $event, $status='ok', $detail=null,
            $objectType=null, $objectId=null, $externalId=null, $channel=null) {
        return ConnectorEvent::log($this, $direction, $event, $status,
                $detail, $objectType, $objectId, $externalId, $channel);
    }

    function getEvents($limit=50) {
        return ConnectorEvent::objects()
            ->filter(array('connector_id' => $this->getId()))
            ->order_by('-id')
            ->limit($limit);
    }

    function getNumErrors($hours=24) {
        return ConnectorEvent::objects()
            ->filter(array(
                'connector_id' => $this->getId(),
                'status' => 'error',
                'created__gt' => SqlFunction::DATE_SUB(SqlFunction::NOW(),
                    SqlInterval::HOUR($hours)),
            ))->count();
    }

    function getPendingJobs() {
        return ConnectorJob::objects()->filter(array(
            'connector_id' => $this->getId(),
            'status' => ConnectorJob::STATUS_PENDING))->count();
    }

    function getInfo() {
        $info = $this->ht;
        foreach (array('channels') as $k)
            unset($info[$k]);
        $info['isactive'] = $this->isActive() ? 1 : 0;
        $info['inbound'] = $this->acceptsInbound() ? 1 : 0;
        $info['outbound'] = $this->pushesOutbound() ? 1 : 0;
        $info['alerts'] = $this->receivesAlerts() ? 1 : 0;
        $info['sync_status'] = $this->syncsStatus() ? 1 : 0;
        $info['push_notes'] = $this->pushesNotes() ? 1 : 0;
        foreach ($this->getConfig() as $k => $v) {
            if ($k[0] === '_')
                continue;
            $info[$k] = $v;
        }
        return $info;
    }

    function update($vars, &$errors) {
        $vars['name'] = trim((string) $vars['name']);
        if (!$vars['name'])
            $errors['name'] = __('Name is required');
        elseif (($c = self::objects()->filter(array('name' => $vars['name']))->first())
                && $c->getId() != $this->getId())
            $errors['name'] = __('Name already in use');

        if (!$this->getId()) {
            if (!isset($vars['type']) || !ConnectorManager::getTypeName($vars['type']))
                $errors['type'] = __('Select a connector type');
            else
                $this->type = $vars['type'];
        }

        $config = array();
        if (!$errors)
            $config = $this->getBackend()->parseConfig($vars, $errors);

        if ($errors)
            return false;

        $this->name = Format::sanitize($vars['name']);
        $this->notes = Format::sanitize($vars['notes']);
        $this->setFlag(self::FLAG_ACTIVE, isset($vars['isactive']) && $vars['isactive']);
        $this->setFlag(self::FLAG_INBOUND, isset($vars['inbound']));
        $this->setFlag(self::FLAG_OUTBOUND, isset($vars['outbound']));
        $this->setFlag(self::FLAG_ALERTS, isset($vars['alerts']));
        $this->setFlag(self::FLAG_SYNC_STATUS, isset($vars['sync_status']));
        $this->setFlag(self::FLAG_PUSH_NOTES, isset($vars['push_notes']));

        // Preserve internal cache entries (keys starting with "_")
        foreach ($this->getConfig() as $k => $v)
            if ($k[0] === '_' && !isset($config[$k]))
                $config[$k] = $v;

        $this->setConfig($config);
        $this->updated = SqlFunction::NOW();
        if (!$this->save())
            return false;

        $type = array('type' => 'edited');
        Signal::send('object.edited', $this, $type);
        return true;
    }

    function delete() {
        $id = $this->getId();
        foreach (array('ConnectorChannel', 'ConnectorLink', 'ConnectorEvent', 'ConnectorJob') as $class)
            $class::objects()->filter(array('connector_id' => $id))->delete();
        $type = array('type' => 'deleted');
        Signal::send('object.deleted', $this, $type);
        return parent::delete();
    }

    function __toString() {
        return (string) $this->getName();
    }

    static function create($vars=false) {
        $c = new static($vars ?: array());
        $c->created = SqlFunction::NOW();
        $c->updated = SqlFunction::NOW();
        $c->flags = $c->flags ?: 0;
        return $c;
    }

    static function getActive($type=null) {
        $q = self::objects()->filter(array('flags__hasbit' => self::FLAG_ACTIVE));
        if ($type)
            $q = $q->filter(array('type' => $type));
        return $q;
    }

    static function getConnectors($activeOnly=false) {
        $list = array();
        $q = $activeOnly ? self::getActive() : self::objects();
        foreach ($q->values_flat('id', 'name', 'type') as $row)
            $list[$row[0]] = sprintf('%s (%s)', $row[1], ConnectorManager::getTypeName($row[2]));
        return $list;
    }
}

class ConnectorChannel extends VerySimpleModel {
    static $meta = array(
        'table' => CONNECTOR_CHANNEL_TABLE,
        'pk' => array('id'),
        'ordering' => array('name'),
        'joins' => array(
            'connector' => array(
                'constraint' => array('connector_id' => 'Connector.id'),
            ),
            'dept' => array(
                'constraint' => array('dept_id' => 'Dept.id'),
                'null' => true,
            ),
        ),
    );

    const FLAG_INBOUND  = 0x0001;   // Create tickets from this channel
    const FLAG_OUTBOUND = 0x0002;   // Push ticket activity to this channel
    const FLAG_ALERTS   = 0x0004;   // Receive SLA / incident alerts
    const FLAG_DEFAULT  = 0x0008;   // Fallback channel for unmapped departments
    const FLAG_DISABLED = 0x0010;

    var $_extra;

    function getId() { return $this->id; }
    function getConnectorId() { return $this->connector_id; }
    function getConnector() { return $this->connector; }
    function getDeptId() { return $this->dept_id; }
    function getDept() { return $this->dept; }
    function getChannelId() { return $this->channel_id; }
    function getName() { return $this->name ?: $this->channel_id; }
    function getCreateDate() { return $this->created; }

    function getDeptName() {
        if (($d = $this->getDept()))
            return $d->getName();
        return $this->isDefault() ? __('Any (default)') : __('— None —');
    }

    function hasFlag($flag) {
        return (((int) $this->flags) & $flag) != 0;
    }

    function setFlag($flag, $val) {
        if ($val)
            $this->flags = ((int) $this->flags) | $flag;
        else
            $this->flags = ((int) $this->flags) & ~$flag;
    }

    function isInbound() { return $this->hasFlag(self::FLAG_INBOUND); }
    function isOutbound() { return $this->hasFlag(self::FLAG_OUTBOUND); }
    function receivesAlerts() { return $this->hasFlag(self::FLAG_ALERTS); }
    function isDefault() { return $this->hasFlag(self::FLAG_DEFAULT); }
    function isEnabled() { return !$this->hasFlag(self::FLAG_DISABLED); }

    function getExtra() {
        if (!isset($this->_extra)) {
            $e = $this->extra ? json_decode($this->extra, true) : null;
            $this->_extra = is_array($e) ? $e : array();
        }
        return $this->_extra;
    }

    function getSetting($key, $default=null) {
        $e = $this->getExtra();
        if (isset($e[$key]) && $e[$key] !== '' && $e[$key] !== null)
            return $e[$key];
        return $default;
    }

    function setExtra(array $extra) {
        $this->_extra = $extra;
        $this->extra = json_encode($extra, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    function getDirections() {
        $d = array();
        if ($this->isInbound()) $d[] = __('Inbound');
        if ($this->isOutbound()) $d[] = __('Outbound');
        if ($this->receivesAlerts()) $d[] = __('Alerts');
        return $d;
    }

    function getInfo() {
        $info = $this->ht;
        foreach (array('connector', 'dept') as $k)
            unset($info[$k]);
        $info['inbound'] = $this->isInbound() ? 1 : 0;
        $info['outbound'] = $this->isOutbound() ? 1 : 0;
        $info['alerts'] = $this->receivesAlerts() ? 1 : 0;
        $info['is_default'] = $this->isDefault() ? 1 : 0;
        $info['disabled'] = $this->isEnabled() ? 0 : 1;
        foreach ($this->getExtra() as $k => $v)
            $info[$k] = $v;
        return $info;
    }

    function update($vars, &$errors) {
        $connector = $this->getConnector();
        $vars['channel_id'] = trim((string) $vars['channel_id']);
        $vars['name'] = trim((string) $vars['name']);
        if (!$vars['dept_id'] && !isset($vars['is_default']))
            $errors['dept_id'] = __('Select a department (or mark the channel as default)');
        elseif ($vars['dept_id'] && !Dept::lookup((int) $vars['dept_id']))
            $errors['dept_id'] = __('Unknown department');

        $extra = array();
        if ($connector) {
            $backend = $connector->getBackend();
            if (!$vars['channel_id'] && !$backend->allowsEmptyChannelId($vars))
                $errors['channel_id'] = sprintf(__('%s is required'), $backend->getChannelIdLabel());
            $extra = $backend->parseChannelExtra($vars, $errors, $this);
            if (!$errors)
                $backend->validateChannel($vars, $extra, $errors);
        }

        if (!$errors && $vars['channel_id'] && $connector) {
            $dupe = ConnectorChannel::objects()->filter(array(
                'connector_id' => $connector->getId(),
                'channel_id' => $vars['channel_id'],
            ))->first();
            if ($dupe && $dupe->getId() != $this->getId())
                $errors['channel_id'] = __('This channel is already mapped');
        }

        if ($errors)
            return false;

        $this->dept_id = (int) $vars['dept_id'];
        $this->channel_id = Format::sanitize($vars['channel_id']);
        $this->name = Format::sanitize($vars['name']);
        $this->setFlag(self::FLAG_INBOUND, isset($vars['inbound']));
        $this->setFlag(self::FLAG_OUTBOUND, isset($vars['outbound']));
        $this->setFlag(self::FLAG_ALERTS, isset($vars['alerts']));
        $this->setFlag(self::FLAG_DEFAULT, isset($vars['is_default']));
        $this->setFlag(self::FLAG_DISABLED, isset($vars['disabled']));
        $this->setExtra($extra);
        $this->updated = SqlFunction::NOW();
        return $this->save();
    }

    function delete() {
        ConnectorLink::objects()->filter(array('channel_id' => $this->getId()))
            ->update(array('channel_id' => 0));
        return parent::delete();
    }

    function __toString() {
        return (string) $this->getName();
    }

    static function create($vars=false) {
        $ch = new static($vars ?: array());
        $ch->created = SqlFunction::NOW();
        $ch->updated = SqlFunction::NOW();
        $ch->flags = $ch->flags ?: 0;
        return $ch;
    }

    /**
     * Channels (of active connectors) routed for a department. When the
     * department has no mapping, channels flagged as default are used.
     *
     * $flag - ConnectorChannel::FLAG_* required on the channel
     * $cflag - Connector::FLAG_* required on the connector
     */
    static function forDept($deptId, $flag=0, $cflag=0, $type=null) {
        $matches = $defaults = array();
        $connectors = Connector::getActive($type);
        foreach ($connectors as $c) {
            if ($cflag && !$c->hasFlag($cflag))
                continue;
            foreach ($c->getChannels() as $ch) {
                if (!$ch->isEnabled())
                    continue;
                if ($flag && !$ch->hasFlag($flag))
                    continue;
                if ($deptId && (int) $ch->getDeptId() === (int) $deptId)
                    $matches[] = $ch;
                elseif ($ch->isDefault())
                    $defaults[$c->getId()][] = $ch;
            }
        }
        // Connector-level fallback: a connector without a mapping for the
        // department uses its default channel(s)
        $seen = array();
        foreach ($matches as $m)
            $seen[$m->getConnectorId()] = true;
        foreach ($defaults as $cid => $list)
            if (!isset($seen[$cid]))
                $matches = array_merge($matches, $list);
        return $matches;
    }

    static function lookupByExternal($connectorId, $channelId) {
        if (!$channelId)
            return null;
        return self::objects()->filter(array(
            'connector_id' => (int) $connectorId,
            'channel_id' => (string) $channelId,
        ))->first();
    }

    static function forConnector($connectorId) {
        return self::objects()->filter(array('connector_id' => (int) $connectorId));
    }
}

class ConnectorLink extends VerySimpleModel {
    static $meta = array(
        'table' => CONNECTOR_LINK_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'connector' => array(
                'constraint' => array('connector_id' => 'Connector.id'),
            ),
            'channel' => array(
                'constraint' => array('channel_id' => 'ConnectorChannel.id'),
                'null' => true,
            ),
        ),
    );

    const TYPE_TICKET   = 'T';
    const TYPE_ENTRY    = 'E';
    const TYPE_INCIDENT = 'I';

    var $_extra;

    function getId() { return $this->id; }
    function getConnectorId() { return $this->connector_id; }
    function getConnector() { return $this->connector; }
    function getChannelId() { return $this->channel_id; }
    function getChannel() { return $this->channel; }
    function getObjectType() { return $this->object_type; }
    function getObjectId() { return $this->object_id; }
    function getExternalId() { return $this->external_id; }
    function getExternalKey() { return $this->external_key; }
    function getExternalUrl() { return $this->external_url; }
    function getCreateDate() { return $this->created; }
    function getUpdateDate() { return $this->updated; }

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

    function getLabel() {
        return $this->external_key ?: $this->external_id;
    }

    function getObject() {
        switch ($this->object_type) {
        case self::TYPE_TICKET:
            return Ticket::lookup((int) $this->object_id);
        case self::TYPE_ENTRY:
            return ThreadEntry::lookup((int) $this->object_id);
        case self::TYPE_INCIDENT:
            require_once INCLUDE_DIR.'class.incident.php';
            return Incident::lookup((int) $this->object_id);
        }
        return null;
    }

    static function forObject($connectorId, $type, $objectId) {
        return self::objects()->filter(array(
            'connector_id' => (int) $connectorId,
            'object_type' => $type,
            'object_id' => (int) $objectId,
        ))->first();
    }

    static function forTicket($ticketId, $connectorId=null) {
        $q = self::objects()->filter(array(
            'object_type' => self::TYPE_TICKET,
            'object_id' => (int) $ticketId,
        ))->order_by('id');
        if ($connectorId)
            $q = $q->filter(array('connector_id' => (int) $connectorId));
        return $q;
    }

    static function byExternal($connectorId, $externalId, $type=null) {
        if (!$externalId)
            return null;
        $q = self::objects()->filter(array(
            'connector_id' => (int) $connectorId,
            'external_id' => (string) $externalId,
        ));
        if ($type)
            $q = $q->filter(array('object_type' => $type));
        return $q->first();
    }

    /**
     * Create or update the link between an osTicket object and an
     * external object.
     */
    static function record(Connector $connector, $type, $objectId, $externalId,
            $options=array()) {
        $link = self::forObject($connector->getId(), $type, $objectId);
        if (!$link) {
            $link = new static(array(
                'connector_id' => $connector->getId(),
                'object_type' => $type,
                'object_id' => (int) $objectId,
                'created' => SqlFunction::NOW(),
            ));
        }
        $link->external_id = (string) $externalId;
        if (isset($options['channel']) && $options['channel'])
            $link->channel_id = $options['channel'] instanceof ConnectorChannel
                ? $options['channel']->getId() : (int) $options['channel'];
        if (isset($options['key']))
            $link->external_key = mb_substr((string) $options['key'], 0, 64);
        if (isset($options['url']))
            $link->external_url = mb_substr((string) $options['url'], 0, 512);
        if (isset($options['extra']) && is_array($options['extra']))
            $link->setExtra(array_merge($link->getExtra(), $options['extra']));
        $link->updated = SqlFunction::NOW();
        return $link->save() ? $link : null;
    }
}

class ConnectorEvent extends VerySimpleModel {
    static $meta = array(
        'table' => CONNECTOR_EVENT_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'connector' => array(
                'constraint' => array('connector_id' => 'Connector.id'),
            ),
        ),
    );

    function getId() { return $this->id; }
    function getDirection() { return $this->direction; }
    function getEvent() { return $this->event; }
    function getStatus() { return $this->status; }
    function getDetail() { return $this->detail; }
    function getCreateDate() { return $this->created; }
    function getObjectType() { return $this->object_type; }
    function getObjectId() { return $this->object_id; }
    function getExternalId() { return $this->external_id; }

    function isError() { return $this->status == 'error'; }

    static function log(Connector $connector, $direction, $event, $status='ok',
            $detail=null, $objectType=null, $objectId=null, $externalId=null,
            $channel=null) {
        if (is_array($detail) || is_object($detail))
            $detail = json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $e = new static(array(
            'connector_id' => $connector->getId(),
            'channel_id' => $channel instanceof ConnectorChannel
                ? $channel->getId() : (int) $channel,
            'direction' => $direction == 'in' ? 'in' : 'out',
            'event' => mb_substr((string) $event, 0, 64),
            'status' => mb_substr((string) $status, 0, 16),
            'detail' => $detail === null ? null : mb_substr((string) $detail, 0, 8000),
            'object_type' => $objectType,
            'object_id' => $objectId ? (int) $objectId : null,
            'external_id' => $externalId === null ? null : mb_substr((string) $externalId, 0, 255),
            'created' => SqlFunction::NOW(),
        ));
        $e->save();
        if ($status == 'error')
            $connector->touch($detail);
        return $e;
    }

    static function purge($days=30) {
        return self::objects()->filter(array(
            'created__lt' => SqlFunction::DATE_SUB(SqlFunction::NOW(),
                SqlInterval::DAY($days)),
        ))->delete();
    }

    static function recent($limit=20, $status=null) {
        $q = self::objects()->order_by('-id')->limit($limit);
        if ($status)
            $q = $q->filter(array('status' => $status));
        return $q;
    }
}

class ConnectorJob extends VerySimpleModel {
    static $meta = array(
        'table' => CONNECTOR_JOB_TABLE,
        'pk' => array('id'),
        'joins' => array(
            'connector' => array(
                'constraint' => array('connector_id' => 'Connector.id'),
            ),
        ),
    );

    const STATUS_PENDING = 'pending';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    const MAX_ATTEMPTS = 8;

    var $_payload;

    function getId() { return $this->id; }
    function getAction() { return $this->action; }
    function getConnector() { return $this->connector; }
    function getStatus() { return $this->status; }
    function getAttempts() { return (int) $this->attempts; }
    function getLastError() { return $this->last_error; }

    function getChannel() {
        return $this->channel_id ? ConnectorChannel::lookup((int) $this->channel_id) : null;
    }

    function getPayload() {
        if (!isset($this->_payload)) {
            $p = $this->payload ? json_decode($this->payload, true) : null;
            $this->_payload = is_array($p) ? $p : array();
        }
        return $this->_payload;
    }

    function isPending() {
        return $this->status == self::STATUS_PENDING;
    }

    /**
     * Execute the job (synchronously). Failures are retried by cron
     * with exponential backoff.
     */
    function run() {
        if (!$this->isPending())
            return false;

        $connector = $this->getConnector();
        $this->attempts = $this->getAttempts() + 1;
        $this->updated = SqlFunction::NOW();

        if (!$connector || !$connector->isActive()) {
            $this->status = self::STATUS_FAILED;
            $this->last_error = __('Connector unavailable');
            $this->save();
            return false;
        }

        $error = null;
        try {
            $backend = $connector->getBackend();
            $ok = $backend->handleJob($this);
            if ($ok === false)
                $error = $backend->getLastError() ?: __('Delivery failed');
        }
        catch (Throwable $t) {
            $error = $t->getMessage();
        }

        if ($error === null) {
            $this->status = self::STATUS_DONE;
            $this->last_error = null;
            $this->next_attempt = null;
            $this->save();
            return true;
        }

        $this->last_error = mb_substr((string) $error, 0, 2000);
        if ($this->getAttempts() >= self::MAX_ATTEMPTS) {
            $this->status = self::STATUS_FAILED;
            $this->next_attempt = null;
            $connector->logEvent('out', $this->getAction(), 'error',
                sprintf(__('Giving up after %d attempts: %s'), $this->getAttempts(), $error),
                $this->object_type, $this->object_id, null, $this->channel_id);
        }
        else {
            // 2, 4, 8, 16 ... minutes (capped to 2 hours)
            $delay = min(7200, 60 * pow(2, $this->getAttempts()));
            $this->next_attempt = SqlFunction::DATE_ADD(SqlFunction::NOW(),
                SqlInterval::SECOND($delay));
        }
        $this->save();
        return false;
    }

    static function enqueue(Connector $connector, $action, array $payload=array(),
            $objectType=null, $objectId=null, $channel=null) {
        $job = new static(array(
            'connector_id' => $connector->getId(),
            'channel_id' => $channel instanceof ConnectorChannel
                ? $channel->getId() : (int) $channel,
            'action' => mb_substr((string) $action, 0, 64),
            'object_type' => $objectType,
            'object_id' => $objectId ? (int) $objectId : null,
            'payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'next_attempt' => SqlFunction::NOW(),
            'created' => SqlFunction::NOW(),
            'updated' => SqlFunction::NOW(),
        ));
        return $job->save() ? $job : null;
    }

    /**
     * Run pending jobs whose retry time has come (cron)
     */
    static function processPending($max=25) {
        $jobs = self::objects()->filter(array(
            'status' => self::STATUS_PENDING,
            Q::any(array(
                'next_attempt__isnull' => true,
                'next_attempt__lte' => SqlFunction::NOW(),
            )),
        ))->order_by('id')->limit($max);
        $n = 0;
        foreach ($jobs as $job) {
            $job->run();
            $n++;
        }
        return $n;
    }

    static function purge($days=14) {
        return self::objects()->filter(array(
            'status__in' => array(self::STATUS_DONE, self::STATUS_FAILED),
            'updated__lt' => SqlFunction::DATE_SUB(SqlFunction::NOW(),
                SqlInterval::DAY($days)),
        ))->delete();
    }

    static function countPending() {
        return self::objects()->filter(array('status' => self::STATUS_PENDING))->count();
    }
}

/**
 * Normalized inbound request handed to connector backends
 */
class ConnectorRequest {
    var $method = 'POST';
    var $headers = array();
    var $raw = '';
    var $json = null;
    var $query = array();
    var $remoteAddr = '';

    static function fromGlobals() {
        $r = new static();
        $r->method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'POST';
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0)
                $r->headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
        if (isset($_SERVER['CONTENT_TYPE']))
            $r->headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        $r->raw = (string) @file_get_contents('php://input');
        if ($r->raw !== '') {
            $json = json_decode($r->raw, true);
            if (json_last_error() === JSON_ERROR_NONE)
                $r->json = $json;
        }
        if (!$r->json && $_POST)
            $r->json = $_POST;
        $r->query = $_GET;
        $r->remoteAddr = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        return $r;
    }

    function header($name, $default=null) {
        $name = strtolower($name);
        return isset($this->headers[$name]) ? $this->headers[$name] : $default;
    }

    function param($name, $default=null) {
        if (is_array($this->json) && array_key_exists($name, $this->json))
            return $this->json[$name];
        if (array_key_exists($name, $this->query))
            return $this->query[$name];
        return $default;
    }
}

/**
 * Base class for connector drivers
 */
abstract class ConnectorBackend {
    static $type = '';
    static $name = '';
    static $description = '';
    static $icon = 'link';

    protected $connector;
    protected $lastError = null;

    function __construct(Connector $connector) {
        $this->connector = $connector;
    }

    function getConnector() { return $this->connector; }
    function getLastError() { return $this->lastError; }
    static function getTypeName() { return __(static::$name); }
    static function getDescription() { return __(static::$description); }

    /* ----------------- Admin form metadata ------------------- */

    /**
     * Fields rendered on the connector configuration page.
     *
     * name => array(
     *   'label' => ..., 'type' => text|password|textarea|boolean|select|number|staff,
     *   'hint' => ..., 'required' => bool, 'choices' => array(), 'default' => ...)
     */
    abstract function getConfigFields();

    /**
     * Additional per-channel fields (same format as getConfigFields)
     */
    function getChannelFields() { return array(); }
    function getChannelIdLabel() { return __('Channel ID'); }
    function getChannelIdHint() { return ''; }
    function allowsEmptyChannelId($vars) { return false; }
    function getSetupInstructions() { return ''; }
    function supportsInbound() { return true; }
    function supportsOutbound() { return true; }
    function supportsAlerts() { return true; }

    /**
     * Collect configuration values from submitted form data
     */
    function parseConfig($vars, &$errors) {
        $config = array();
        $current = $this->connector->getConfig();
        foreach ($this->getConfigFields() as $name => $F) {
            $type = isset($F['type']) ? $F['type'] : 'text';
            $value = isset($vars[$name]) ? $vars[$name] : null;
            switch ($type) {
            case 'boolean':
                $value = isset($vars[$name]) && $vars[$name] ? 1 : 0;
                break;
            case 'password':
                $value = trim((string) $value);
                // Blank password keeps the stored secret
                if ($value === '' && isset($current[$name]))
                    $value = $current[$name];
                break;
            case 'checklist':
                $value = is_array($value) ? array_values($value) : array();
                break;
            case 'number':
                $value = $value === null || $value === '' ? '' : (int) $value;
                break;
            case 'staff':
                $value = (int) $value;
                break;
            case 'textarea':
                $value = trim((string) $value);
                break;
            default:
                $value = trim(Format::sanitize((string) $value));
            }
            if (!empty($F['required']) && ($value === '' || $value === null))
                $errors[$name] = sprintf(__('%s is required'), $F['label']);
            $config[$name] = $value;
        }
        $this->validateConfig($config, $errors);
        return $config;
    }

    function validateConfig(&$config, &$errors) {
        return true;
    }

    function parseChannelExtra($vars, &$errors, $channel=null) {
        $extra = array();
        $current = $channel ? $channel->getExtra() : array();
        foreach ($this->getChannelFields() as $name => $F) {
            $type = isset($F['type']) ? $F['type'] : 'text';
            $value = isset($vars[$name]) ? $vars[$name] : null;
            if ($type == 'boolean')
                $value = isset($vars[$name]) && $vars[$name] ? 1 : 0;
            elseif ($type == 'password') {
                $value = trim((string) $value);
                if ($value === '' && isset($current[$name]))
                    $value = $current[$name];
            }
            else
                $value = trim((string) $value);
            if (!empty($F['required']) && $value === '')
                $errors[$name] = sprintf(__('%s is required'), $F['label']);
            $extra[$name] = $value;
        }
        return $extra;
    }

    function validateChannel(&$vars, &$extra, &$errors) {
        return true;
    }

    /**
     * Connectivity test. Returns array('ok' => bool, 'message' => string)
     */
    function test() {
        return array('ok' => true, 'message' => __('Nothing to test for this connector'));
    }

    /* ----------------- Outbound ------------------- */

    function pushTicketCreated(Ticket $ticket, ConnectorChannel $channel) { return true; }
    function pushEntry(ThreadEntry $entry, Ticket $ticket, ConnectorChannel $channel) { return true; }
    function pushTicketEvent(Ticket $ticket, $event, array $data, ConnectorChannel $channel) { return true; }
    function sendAlert(ConnectorChannel $channel, array $alert) { return true; }

    /* ----------------- Inbound -------------------- */

    /**
     * Handle an inbound webhook request. Returns a response description:
     * array('status' => 200, 'json' => array(...)) or ('text' => ...)
     */
    function handleInbound(ConnectorRequest $request) {
        return array('status' => 404, 'text' => __('Inbound requests are not supported'));
    }

    /* ----------------- Jobs ----------------------- */

    function handleJob(ConnectorJob $job) {
        $payload = $job->getPayload();
        $channel = $job->getChannel();
        $this->lastError = null;
        switch ($job->getAction()) {
        case 'ticket.created':
            if (!$channel || !($ticket = Ticket::lookup((int) $payload['ticket_id'])))
                return true; // nothing to do anymore
            return $this->pushTicketCreated($ticket, $channel);
        case 'entry.created':
            if (!$channel || !($ticket = Ticket::lookup((int) $payload['ticket_id']))
                    || !($entry = ThreadEntry::lookup((int) $payload['entry_id'])))
                return true;
            return $this->pushEntry($entry, $ticket, $channel);
        case 'ticket.event':
            if (!$channel || !($ticket = Ticket::lookup((int) $payload['ticket_id'])))
                return true;
            return $this->pushTicketEvent($ticket, $payload['event'],
                isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array(),
                $channel);
        case 'alert':
            if (!$channel)
                return true;
            return $this->sendAlert($channel, $payload);
        }
        $this->lastError = sprintf(__('Unknown action: %s'), $job->getAction());
        return false;
    }

    /* ----------------- Helpers -------------------- */

    protected function fail($message, $event=null, $objectType=null, $objectId=null, $channel=null) {
        $this->lastError = $message;
        if ($event)
            $this->connector->logEvent('out', $event, 'error', $message,
                $objectType, $objectId, null, $channel);
        return false;
    }

    protected function ok($event, $detail=null, $objectType=null, $objectId=null,
            $externalId=null, $channel=null, $direction='out') {
        $this->connector->logEvent($direction, $event, 'ok', $detail,
            $objectType, $objectId, $externalId, $channel);
        return true;
    }

    protected function skip($event, $detail=null, $direction='in') {
        $this->connector->logEvent($direction, $event, 'skipped', $detail);
        return true;
    }

    function ticketUrl(Ticket $ticket) {
        global $cfg;
        return sprintf('%s/scp/tickets.php?id=%d', rtrim($cfg->getBaseUrl(), '/'),
            $ticket->getId());
    }

    function ticketTitle(Ticket $ticket) {
        return sprintf('#%s: %s', $ticket->getNumber(), $ticket->getSubject());
    }

    function ticketFacts(Ticket $ticket) {
        $facts = array();
        if (($user = $ticket->getOwner()))
            $facts[] = array(__('From'), sprintf('%s <%s>', (string) $user->getName(),
                (string) $ticket->getEmail()));
        $facts[] = array(__('Department'), (string) $ticket->getDeptName());
        if (($p = $ticket->getPriority()))
            $facts[] = array(__('Priority'), (string) $p);
        $facts[] = array(__('Status'), (string) $ticket->getStatus());
        if ($ticket->isAssigned())
            $facts[] = array(__('Assigned To'), implode(', ', array_map('strval', $ticket->getAssignees())));
        if (($sla = $ticket->getSLA()))
            $facts[] = array(__('SLA'), (string) $sla->getName());
        if (($due = $ticket->getEstDueDate()))
            $facts[] = array(__('Due'), Format::datetime($due));
        $facts[] = array(__('Source'), (string) $ticket->getSource());
        return $facts;
    }

    function entryText(ThreadEntry $entry, $max=4000) {
        $body = $entry->getBody();
        $text = $body ? $body->getClean() : '';
        if ($entry->format == 'html' || preg_match('/<[a-z][^>]*>/i', $text))
            $text = Format::html2text($text, 100, false);
        $text = trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
        if (mb_strlen($text) > $max)
            $text = mb_substr($text, 0, $max) . ' […]';
        return $text;
    }

    function entryTypeLabel(ThreadEntry $entry) {
        switch ($entry->getType()) {
        case 'M': return __('Message');
        case 'R': return __('Reply');
        case 'N': return __('Internal Note');
        }
        return __('Entry');
    }

    function posterName(ThreadEntry $entry) {
        if (($s = $entry->getStaff()))
            return (string) $s->getName();
        if (($u = $entry->getUser()))
            return (string) $u->getName();
        return (string) ($entry->getPoster() ?: __('System'));
    }

    function firstMessageText(Ticket $ticket, $max=4000) {
        if (($m = $ticket->getLastMessage()))
            return $this->entryText($m, $max);
        return '';
    }

    function eventTitle(Ticket $ticket, $event, array $data) {
        $n = '#' . $ticket->getNumber();
        switch ($event) {
        case 'closed':
            return sprintf(__('Ticket %s closed'), $n);
        case 'reopened':
            return sprintf(__('Ticket %s reopened'), $n);
        case 'overdue':
            return sprintf(__('Ticket %s is overdue'), $n);
        case 'assigned':
            return sprintf(__('Ticket %s assigned to %s'), $n,
                $ticket->isAssigned() ? implode(', ', array_map('strval', $ticket->getAssignees())) : __('nobody'));
        case 'transferred':
            return sprintf(__('Ticket %s transferred to %s'), $n, $ticket->getDeptName());
        case 'escalated':
            return sprintf(__('Ticket %s escalated'), $n);
        }
        return sprintf(__('Ticket %s: %s'), $n, $event);
    }

    /**
     * Resolve an osTicket priority id to a Linear-style urgency
     * (1 urgent … 4 low)
     */
    function priorityUrgency(Ticket $ticket) {
        if (($pid = $ticket->getPriorityId()) && ($p = Priority::lookup((int) $pid)))
            return (int) $p->getUrgency();
        return 3;
    }
}

/**
 * Central registry, signal wiring and routing helpers
 */
class ConnectorManager {
    static $types = array(
        'linear' => array('class' => 'LinearConnector', 'file' => 'connectors/linear.php'),
        'teams' => array('class' => 'TeamsConnector', 'file' => 'connectors/teams.php'),
        'webhook' => array('class' => 'WebhookConnector', 'file' => 'connectors/webhook.php'),
    );

    static $inbound = null;
    static $bootstrapped = false;

    static function getTypes() {
        $types = array();
        foreach (self::$types as $type => $info) {
            $class = self::getBackendClass($type);
            $types[$type] = $class ? $class::getTypeName() : ucfirst($type);
        }
        return $types;
    }

    static function getTypeName($type) {
        if (!isset(self::$types[$type]))
            return null;
        $class = self::getBackendClass($type);
        return $class ? $class::getTypeName() : ucfirst($type);
    }

    static function getTypeDescription($type) {
        if (($class = self::getBackendClass($type)))
            return $class::getDescription();
        return '';
    }

    static function getBackendClass($type) {
        if (!isset(self::$types[$type]))
            return null;
        $info = self::$types[$type];
        if (!class_exists($info['class'])) {
            $file = INCLUDE_DIR . $info['file'];
            if (file_exists($file))
                require_once $file;
        }
        return class_exists($info['class']) ? $info['class'] : null;
    }

    static function getBackend(Connector $connector) {
        if (!($class = self::getBackendClass($connector->getType())))
            throw new Exception(sprintf('%s: unsupported connector type', $connector->getType()));
        return new $class($connector);
    }

    static function register($type, $class, $file=null) {
        self::$types[$type] = array('class' => $class, 'file' => $file);
    }

    /**
     * Wire the framework into the system signals. Called once per
     * request from main.inc.php
     */
    static function bootstrap() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;
        Signal::connect('ticket.created', array('ConnectorManager', 'onTicketCreated'));
        Signal::connect('threadentry.created', array('ConnectorManager', 'onEntryCreated'));
        Signal::connect('object.created', array('ConnectorManager', 'onTicketEvent'), 'Ticket');
        Signal::connect('ticket.overdue', array('ConnectorManager', 'onTicketOverdue'));
        Signal::connect('cron', array('ConnectorManager', 'onCron'));
    }

    /* ---------------- Inbound context ---------------- */

    /**
     * Mark that objects created from now on originate from the given
     * connector (used to record links and to avoid echoing activity
     * back to the originating system)
     */
    static function beginInbound(Connector $connector, $channel=null, $externalId=null,
            array $extra=array()) {
        self::$inbound = array(
            'connector' => $connector,
            'channel' => $channel,
            'external_id' => $externalId,
            'extra' => $extra,
        );
    }

    static function endInbound() {
        self::$inbound = null;
    }

    static function getInboundContext() {
        return self::$inbound;
    }

    static function isInboundFrom($connectorId) {
        return self::$inbound
            && self::$inbound['connector']->getId() == $connectorId;
    }

    /* ---------------- Signal handlers ---------------- */

    static function onTicketCreated($ticket, &$data=null) {
        if (!$ticket instanceof Ticket)
            return;
        try {
            $ctx = self::$inbound;
            if ($ctx && $ctx['external_id']) {
                ConnectorLink::record($ctx['connector'], ConnectorLink::TYPE_TICKET,
                    $ticket->getId(), $ctx['external_id'], array(
                        'channel' => $ctx['channel'],
                        'key' => isset($ctx['extra']['key']) ? $ctx['extra']['key'] : null,
                        'url' => isset($ctx['extra']['url']) ? $ctx['extra']['url'] : null,
                        'extra' => isset($ctx['extra']['extra']) ? $ctx['extra']['extra'] : array(),
                    ));
            }
            foreach (ConnectorChannel::forDept($ticket->getDeptId(),
                    ConnectorChannel::FLAG_OUTBOUND, Connector::FLAG_OUTBOUND) as $ch) {
                $c = $ch->getConnector();
                if ($ctx && $ctx['connector']->getId() == $c->getId())
                    continue;
                if (ConnectorLink::forObject($c->getId(), ConnectorLink::TYPE_TICKET, $ticket->getId()))
                    continue;
                self::dispatch($c, $ch, 'ticket.created',
                    array('ticket_id' => $ticket->getId()),
                    ConnectorLink::TYPE_TICKET, $ticket->getId());
            }
        }
        catch (Throwable $t) {
            self::logError('ticket.created', $t);
        }
    }

    static function onEntryCreated($entry, &$data=null) {
        if (!$entry instanceof ThreadEntry)
            return;
        try {
            $thread = $entry->getThread();
            if (!$thread || $thread->getObjectType() != ObjectModel::OBJECT_TYPE_TICKET)
                return;
            $ticket = $thread->getObject();
            if (!$ticket instanceof Ticket)
                return;

            $ctx = self::$inbound;
            if ($ctx && $ctx['external_id']) {
                ConnectorLink::record($ctx['connector'], ConnectorLink::TYPE_ENTRY,
                    $entry->getId(), $ctx['external_id'], array('channel' => $ctx['channel']));
            }

            // The original message is delivered with the "ticket.created"
            // event
            if ($entry->getType() == 'M' && $thread->getNumEntries() <= 1)
                return;

            foreach (ConnectorChannel::forDept($ticket->getDeptId(),
                    ConnectorChannel::FLAG_OUTBOUND, Connector::FLAG_OUTBOUND) as $ch) {
                $c = $ch->getConnector();
                if ($ctx && $ctx['connector']->getId() == $c->getId())
                    continue;
                if ($entry->getType() == 'N' && !$c->pushesNotes())
                    continue;
                self::dispatch($c, $ch, 'entry.created', array(
                    'ticket_id' => $ticket->getId(),
                    'entry_id' => $entry->getId(),
                    'type' => $entry->getType(),
                ), ConnectorLink::TYPE_ENTRY, $entry->getId());
            }
        }
        catch (Throwable $t) {
            self::logError('entry.created', $t);
        }
    }

    /**
     * Ticket lifecycle events (object.created signal with a type)
     */
    static function onTicketEvent($ticket, &$data=null) {
        if (!$ticket instanceof Ticket || !is_array($data) || !isset($data['type']))
            return;
        $event = $data['type'];
        if (!in_array($event, array('closed', 'reopened', 'assigned', 'transferred')))
            return;
        try {
            $ctx = self::$inbound;
            foreach (ConnectorChannel::forDept($ticket->getDeptId(),
                    ConnectorChannel::FLAG_OUTBOUND, Connector::FLAG_OUTBOUND) as $ch) {
                $c = $ch->getConnector();
                if ($ctx && $ctx['connector']->getId() == $c->getId())
                    continue;
                if (in_array($event, array('closed', 'reopened')) && !$c->syncsStatus())
                    continue;
                self::dispatch($c, $ch, 'ticket.event', array(
                    'ticket_id' => $ticket->getId(),
                    'event' => $event,
                    'data' => self::scalarize($data),
                ), ConnectorLink::TYPE_TICKET, $ticket->getId());
            }
        }
        catch (Throwable $t) {
            self::logError('ticket.event', $t);
        }
    }

    static function onTicketOverdue($ticket, &$data=null) {
        if (!$ticket instanceof Ticket)
            return;
        try {
            self::notifyTicket($ticket, array(
                'kind' => 'sla',
                'event' => 'overdue',
                'severity' => 'warning',
                'title' => sprintf(__('Ticket #%s is overdue'), $ticket->getNumber()),
                'text' => sprintf(__('%s has passed its due date (%s).'),
                    $ticket->getSubject(),
                    $ticket->getEstDueDate() ? Format::datetime($ticket->getEstDueDate()) : __('unknown')),
                'ticket_id' => $ticket->getId(),
            ));
        }
        catch (Throwable $t) {
            self::logError('ticket.overdue', $t);
        }
    }

    static function onCron($object=null, &$data=null) {
        try {
            ConnectorJob::processPending();
            // House keeping (roughly once a day on a 5 minute cron)
            if (mt_rand(1, 288) == 7) {
                ConnectorJob::purge();
                ConnectorEvent::purge();
            }
        }
        catch (Throwable $t) {
            self::logError('cron', $t);
        }
    }

    /* ---------------- Routing ---------------- */

    static function dispatch(Connector $connector, $channel, $action, array $payload,
            $objectType=null, $objectId=null) {
        if (!($job = ConnectorJob::enqueue($connector, $action, $payload,
                $objectType, $objectId, $channel)))
            return false;
        return $job->run();
    }

    /**
     * Send an alert (incident / SLA) to the channels of a department
     * flagged for alerts.
     *
     * $alert keys: kind (incident|sla|system), event, severity, title,
     * text, url, facts (array of [label, value]), ticket_id, incident_id
     */
    static function notifyDept($deptId, array $alert) {
        $n = 0;
        foreach (ConnectorChannel::forDept($deptId, ConnectorChannel::FLAG_ALERTS,
                Connector::FLAG_ALERTS) as $ch) {
            if (self::dispatch($ch->getConnector(), $ch, 'alert', $alert,
                    isset($alert['incident_id']) ? ConnectorLink::TYPE_INCIDENT
                        : (isset($alert['ticket_id']) ? ConnectorLink::TYPE_TICKET : null),
                    isset($alert['incident_id']) ? $alert['incident_id']
                        : (isset($alert['ticket_id']) ? $alert['ticket_id'] : null)))
                $n++;
        }
        return $n;
    }

    static function notifyTicket(Ticket $ticket, array $alert) {
        $alert['ticket_id'] = $ticket->getId();
        if (!isset($alert['url']))
            $alert['url'] = sprintf('%s/scp/tickets.php?id=%d',
                rtrim(self::baseUrl(), '/'), $ticket->getId());
        return self::notifyDept($ticket->getDeptId(), $alert);
    }

    static function baseUrl() {
        global $cfg;
        return $cfg ? $cfg->getBaseUrl() : '';
    }

    static function sourceFor(Connector $connector) {
        switch ($connector->getType()) {
        case 'linear': return 'Linear';
        case 'teams': return 'Teams';
        }
        return 'API';
    }

    /* ---------------- Inbound helpers ---------------- */

    /**
     * Create a ticket on behalf of an external system.
     *
     * $data: subject, message (string|ThreadEntryBody), name, email,
     *        priority (urgency 1-4 or priority id), topic_id, external_id,
     *        external_key, external_url, attribution (string appended)
     */
    static function createTicket(Connector $connector, $channel, array $data, &$errors) {
        global $cfg;

        if (!$channel || !$channel->isInbound()) {
            $errors['err'] = __('Channel does not accept inbound tickets');
            return null;
        }

        $email = isset($data['email']) && Validator::is_email($data['email'])
            ? $data['email'] : $connector->getSetting('reporter_email');
        if (!$email)
            $email = $cfg->getDefaultEmailAddress();

        $message = isset($data['message']) ? $data['message'] : '';
        if (!$message instanceof ThreadEntryBody) {
            $message = trim((string) $message);
            if (!empty($data['attribution']))
                $message = $data['attribution'] . "\n\n" . $message;
            $message = new TextThreadEntryBody($message ?: (string) $data['subject']);
        }

        $vars = array(
            'name' => Format::sanitize(trim((string) (isset($data['name']) ? $data['name'] : ''))) ?: $connector->getTypeName(),
            'email' => $email,
            'subject' => mb_substr(Format::sanitize(trim((string) $data['subject'])), 0, 255) ?: __('(no subject)'),
            'message' => $message,
            'deptId' => (int) $channel->getDeptId() ?: $cfg->getDefaultDeptId(),
            'source' => self::sourceFor($connector),
            'ip' => isset($data['ip']) ? $data['ip'] : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : ''),
        );
        if (!empty($data['priority']))
            $vars['priorityId'] = self::priorityId($data['priority']);
        if (($topicId = $channel->getSetting('topic_id')))
            $vars['topicId'] = (int) $topicId;
        elseif (!empty($data['topic_id']))
            $vars['topicId'] = (int) $data['topic_id'];
        if (!empty($data['staff_id']))
            $vars['staffId'] = (int) $data['staff_id'];
        if (!empty($data['team_id']))
            $vars['teamId'] = (int) $data['team_id'];

        $autorespond = (bool) $connector->getSetting('autorespond', 0);
        $alerts = (bool) $connector->getSetting('alert_staff', 1);

        self::beginInbound($connector, $channel,
            isset($data['external_id']) ? $data['external_id'] : null, array(
                'key' => isset($data['external_key']) ? $data['external_key'] : null,
                'url' => isset($data['external_url']) ? $data['external_url'] : null,
            ));
        try {
            $ticket = Ticket::create($vars, $errors, 'api', $autorespond, $alerts);
        }
        finally {
            self::endInbound();
        }
        if (!$ticket) {
            if (!$errors)
                $errors['err'] = __('Unable to create ticket');
            return null;
        }
        return $ticket;
    }

    /**
     * Post a reply / note / message on a ticket on behalf of an external
     * system.
     *
     * $data: body (string|ThreadEntryBody), name, email, mode
     *        (reply|note|message|auto), external_id, title
     */
    static function postToTicket(Connector $connector, Ticket $ticket, array $data, &$errors) {
        $mode = isset($data['mode']) ? $data['mode'] : 'auto';
        $body = isset($data['body']) ? $data['body'] : '';
        $text = $body instanceof ThreadEntryBody ? $body->getClean() : trim((string) $body);

        // Inline overrides: "/note …" forces an internal note, "/reply …"
        // forces a public reply
        if (!$body instanceof ThreadEntryBody) {
            if (preg_match('/^\s*(\/note|\/internal|#internal)\b\s*/i', $text)) {
                $mode = 'note';
                $text = preg_replace('/^\s*(\/note|\/internal|#internal)\b\s*/i', '', $text);
            }
            elseif (preg_match('/^\s*(\/reply|\/public)\b\s*/i', $text)) {
                $mode = 'reply';
                $text = preg_replace('/^\s*(\/reply|\/public)\b\s*/i', '', $text);
            }
        }
        if ($mode == 'auto')
            $mode = $connector->getSetting('comment_mode', 'reply');
        if ($text === '') {
            $errors['err'] = __('Message content is required');
            return null;
        }
        if (!$body instanceof ThreadEntryBody)
            $body = new TextThreadEntryBody($text);

        $source = self::sourceFor($connector);
        $name = isset($data['name']) ? Format::sanitize(trim((string) $data['name'])) : '';
        $agent = self::resolveAgent($connector, isset($data['email']) ? $data['email'] : null);

        if ($mode == 'reply' && !$agent)
            // Cannot attribute a public reply — keep it internal
            $mode = 'note';
        if ($mode == 'reply' && $ticket->isClosed() && !$ticket->isReopenable())
            $mode = 'note';

        self::beginInbound($connector, null,
            isset($data['external_id']) ? $data['external_id'] : null);
        try {
            switch ($mode) {
            case 'message':
                $vars = array(
                    'userId' => $ticket->getUserId(),
                    'poster' => $name ?: (string) $ticket->getName(),
                    'message' => $body,
                    'source' => $source,
                    'title' => isset($data['title']) ? $data['title'] : null,
                );
                $entry = $ticket->postMessage($vars, $source, true);
                break;
            case 'reply':
                $vars = array(
                    'staffId' => $agent->getId(),
                    'poster' => $agent,
                    'response' => $body,
                    'reply-to' => 'all',
                    'source' => $source,
                    'title' => isset($data['title']) ? $data['title'] : null,
                );
                $email = (bool) $connector->getSetting('email_replies', 1);
                $entry = $ticket->postReply($vars, $errors, $email, false);
                break;
            default:
                $poster = $agent ?: ($name
                    ? sprintf('%s (%s)', $name, $connector->getTypeName())
                    : $connector->getTypeName());
                $vars = array(
                    'note' => $body,
                    'title' => isset($data['title']) && $data['title']
                        ? $data['title']
                        : sprintf(__('%s comment'), $connector->getTypeName()),
                    'source' => $source,
                    'staffId' => $agent ? $agent->getId() : 0,
                );
                $entry = $ticket->postNote($vars, $errors, $poster, true);
            }
        }
        finally {
            self::endInbound();
        }
        if (!$entry && !$errors)
            $errors['err'] = __('Unable to post to ticket');
        return $entry ?: null;
    }

    /**
     * Find the agent used to attribute replies coming from an external
     * system: the staff member with the same email address, the
     * connector's configured agent, or the department manager.
     */
    static function resolveAgent(Connector $connector, $email=null, Ticket $ticket=null) {
        if ($email && Validator::is_email($email)) {
            $staff = Staff::objects()->filter(array('email' => $email))->first();
            if ($staff && $staff->isActive())
                return $staff;
        }
        if (($id = (int) $connector->getSetting('agent_id'))
                && ($staff = Staff::lookup($id)) && $staff->isActive())
            return $staff;
        if ($ticket && ($dept = $ticket->getDept()) && ($m = $dept->getManager())
                && $m->isActive())
            return $m;
        return null;
    }

    /**
     * Locate a ticket by number, id or (for a connector) an external id
     */
    static function findTicket($ref, Connector $connector=null) {
        $ref = trim((string) $ref, " #\t");
        if ($ref === '')
            return null;
        if (preg_match('/^\d+$/', $ref)) {
            if (($t = Ticket::lookup(array('number' => $ref))))
                return $t;
        }
        if ($connector && ($link = ConnectorLink::byExternal($connector->getId(), $ref,
                ConnectorLink::TYPE_TICKET)))
            return Ticket::lookup((int) $link->getObjectId());
        if (preg_match('/^[A-Z][A-Z0-9]+-\d+$/i', $ref) && $connector) {
            $link = ConnectorLink::objects()->filter(array(
                'connector_id' => $connector->getId(),
                'object_type' => ConnectorLink::TYPE_TICKET,
                'external_key' => strtoupper($ref),
            ))->first();
            if ($link)
                return Ticket::lookup((int) $link->getObjectId());
        }
        return null;
    }

    /**
     * Resolve a priority description, urgency (1-4) or id to a priority id
     */
    static function priorityId($value) {
        if (is_numeric($value)) {
            $v = (int) $value;
            if ($v >= 1 && $v <= 4) {
                $p = Priority::objects()->filter(array('priority_urgency' => $v))->first();
                if ($p)
                    return $p->getId();
            }
            if (Priority::lookup($v))
                return $v;
            return null;
        }
        $value = strtolower(trim((string) $value));
        $map = array('urgent' => 1, 'emergency' => 1, 'critical' => 1,
            'high' => 2, 'medium' => 3, 'normal' => 3, 'low' => 4, 'none' => 4);
        if (isset($map[$value]))
            return self::priorityId($map[$value]);
        foreach (Priority::objects() as $p) {
            if (!strcasecmp($p->getTag(), $value) || !strcasecmp($p->getDesc(), $value))
                return $p->getId();
        }
        return null;
    }

    static function scalarize($data) {
        $out = array();
        foreach ((array) $data as $k => $v) {
            if (is_scalar($v) || $v === null)
                $out[$k] = $v;
            elseif (is_array($v))
                $out[$k] = self::scalarize($v);
            elseif (is_object($v) && method_exists($v, '__toString'))
                $out[$k] = (string) $v;
        }
        return $out;
    }

    static function logError($context, Throwable $t) {
        global $ost;
        if ($ost)
            $ost->logWarning(sprintf('Connector: %s', $context),
                sprintf("%s\n%s:%d", $t->getMessage(), $t->getFile(), $t->getLine()), false);
    }

    /**
     * Status object used when an external system closes a ticket: the
     * "Closed" status if present, otherwise any status in the closed state
     */
    static function closedStatus() {
        static $status;
        if (!isset($status)) {
            $status = TicketStatus::objects()->filter(array('state' => 'closed', 'name' => 'Closed'))->first()
                ?: TicketStatus::objects()->filter(array('state' => 'closed'))->order_by('sort')->first();
        }
        return $status;
    }

    /* ---------------- Health summary (dashboard) ---------------- */

    static function getHealth() {
        $health = array();
        foreach (Connector::objects() as $c) {
            $health[] = array(
                'id' => $c->getId(),
                'name' => $c->getName(),
                'type' => $c->getType(),
                'type_name' => $c->getTypeName(),
                'active' => $c->isActive(),
                'channels' => $c->getNumChannels(),
                'last_run' => $c->getLastRun(),
                'last_error' => $c->getLastError(),
                'errors_24h' => $c->getNumErrors(24),
                'pending' => $c->getPendingJobs(),
            );
        }
        return $health;
    }
}
?>
