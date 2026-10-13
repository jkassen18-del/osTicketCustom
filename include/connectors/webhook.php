<?php
/*********************************************************************
    connectors/webhook.php

    Generic webhook connector for "any other system".

      * Outbound: signed JSON events (ticket.created, ticket.message,
        ticket.response, ticket.note, ticket.closed, ticket.reopened,
        ticket.assigned, ticket.transferred, alert) are POSTed to the
        configured URL (per channel or connector-wide).
      * Inbound: JSON requests create tickets, post replies / notes and
        close or reopen tickets. Authenticated with a shared token
        (X-Webhook-Token header or ?token=) and/or an HMAC signature
        (X-Webhook-Signature: sha256=<hex of body>).

    Channels are routing keys: inbound payloads carry "channel": "<key>"
    to select the department; outbound events for a department go to
    the channel(s) mapped to it.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

class WebhookConnector extends ConnectorBackend {
    static $type = 'webhook';
    static $name = /* @trans */ 'Webhook / Custom API';
    static $description = /* @trans */ 'Signed JSON webhooks in both directions for any other system (chat tools, monitoring, CRMs, automation platforms).';
    static $icon = 'exchange';

    static $events = array(
        'ticket.created' => /* @trans */ 'New ticket',
        'ticket.message' => /* @trans */ 'Message from requester',
        'ticket.response' => /* @trans */ 'Agent reply',
        'ticket.note' => /* @trans */ 'Internal note',
        'ticket.closed' => /* @trans */ 'Ticket closed',
        'ticket.reopened' => /* @trans */ 'Ticket reopened',
        'ticket.assigned' => /* @trans */ 'Ticket assigned',
        'ticket.transferred' => /* @trans */ 'Ticket transferred',
        'alert' => /* @trans */ 'Incident / SLA alerts',
    );

    function getConfigFields() {
        global $cfg;
        return array(
            'url' => array(
                'label' => __('Outbound URL'),
                'type' => 'text',
                'hint' => __('Events are POSTed here as JSON (channels may override this URL).'),
            ),
            'secret' => array(
                'label' => __('Signing secret'),
                'type' => 'password',
                'hint' => __('Used to sign outbound requests (X-OSTicket-Signature: sha256=…) and to verify inbound signatures (X-Webhook-Signature).'),
            ),
            'inbound_token' => array(
                'label' => __('Inbound token'),
                'type' => 'password',
                'hint' => __('Shared token the external system sends as the X-Webhook-Token header (or ?token=). Generated automatically when left blank.'),
            ),
            'events' => array(
                'label' => __('Outbound events'),
                'type' => 'checklist',
                'choices' => self::$events,
                'default' => array('ticket.created', 'ticket.message', 'ticket.response',
                    'ticket.closed', 'ticket.reopened', 'ticket.assigned', 'ticket.transferred', 'alert'),
            ),
            'comment_mode' => array(
                'label' => __('Inbound replies are posted as'),
                'type' => 'select',
                'choices' => array(
                    'reply' => __('Replies to the ticket requester'),
                    'note' => __('Internal notes'),
                ),
                'default' => 'reply',
            ),
            'agent_id' => array(
                'label' => __('Reply as agent'),
                'type' => 'staff',
            ),
            'email_replies' => array(
                'label' => __('Email replies to the requester'),
                'type' => 'boolean',
                'default' => 1,
            ),
            'reporter_email' => array(
                'label' => __('Fallback requester email'),
                'type' => 'text',
                'required' => true,
                'default' => $cfg ? $cfg->getDefaultEmailAddress() : '',
                'hint' => __('Owner of tickets created without an email address in the payload.'),
            ),
            'autorespond' => array(
                'label' => __('Send new-ticket auto-responses'),
                'type' => 'boolean',
                'default' => 0,
            ),
        );
    }

    function validateConfig(&$config, &$errors) {
        if (!empty($config['url']) && !Validator::is_url($config['url']))
            $errors['url'] = __('Enter a valid URL');
        if (empty($config['inbound_token']))
            $config['inbound_token'] = Misc::randCode(32);
        return !$errors;
    }

    function getChannelIdLabel() {
        return __('Routing key');
    }

    function getChannelIdHint() {
        return __('Identifier the external system sends as "channel" in inbound payloads (e.g. "ops", "billing").');
    }

    function getChannelFields() {
        return array(
            'url' => array(
                'label' => __('Outbound URL override'),
                'type' => 'text',
                'hint' => __('Optional URL used instead of the connector-wide URL for this channel.'),
            ),
            'topic_id' => array(
                'label' => __('Help topic'),
                'type' => 'topic',
            ),
        );
    }

    function getSetupInstructions() {
        $url = $this->connector->getId() ? $this->connector->getWebhookUrl()
            : __('(save the connector first)');
        return sprintf(__(
            '<p><b>Inbound endpoint:</b> <code>POST %1$s</code> with header <code>X-Webhook-Token: &lt;inbound token&gt;</code> and a JSON body:</p>'
            .'<pre>{"event": "ticket.create", "channel": "ops", "subject": "Disk full on db-01",\n'
            .' "message": "Usage at 98%%", "name": "Monitoring", "email": "noc@example.com",\n'
            .' "priority": "high", "external_id": "alert-4711", "external_url": "https://…"}\n\n'
            .'{"event": "ticket.reply", "ticket": "123456", "message": "Resolved", "name": "Jane",\n'
            .' "email": "jane@example.com", "internal": false}\n\n'
            .'{"event": "ticket.close", "ticket": "123456", "message": "Fixed"}</pre>'
            .'<p><b>Outbound:</b> selected events are POSTed as JSON to the outbound URL with headers '
            .'<code>X-OSTicket-Event</code>, <code>X-OSTicket-Delivery</code> and <code>X-OSTicket-Signature: sha256=&lt;hmac&gt;</code>.</p>'
            ), Format::htmlchars($url));
    }

    /* ------------------- Outbound ------------------- */

    protected function wants($event) {
        $events = $this->connector->getSetting('events');
        if (!is_array($events))
            return true;
        return in_array($event, $events);
    }

    protected function ticketPayload(Ticket $ticket) {
        $owner = $ticket->getOwner();
        $links = array();
        foreach (ConnectorLink::forTicket($ticket->getId()) as $l)
            $links[] = array('connector' => $l->getConnectorId(), 'external_id' => $l->getExternalId(),
                'key' => $l->getExternalKey(), 'url' => $l->getExternalUrl());
        return array(
            'id' => $ticket->getId(),
            'number' => $ticket->getNumber(),
            'subject' => (string) $ticket->getSubject(),
            'status' => (string) $ticket->getStatus(),
            'state' => $ticket->isOpen() ? 'open' : 'closed',
            'priority' => (string) $ticket->getPriority(),
            'department' => array('id' => $ticket->getDeptId(), 'name' => (string) $ticket->getDeptName()),
            'requester' => array('name' => $owner ? (string) $owner->getName() : '',
                'email' => (string) $ticket->getEmail()),
            'assignees' => array_map('strval', $ticket->getAssignees()),
            'sla' => ($sla = $ticket->getSLA()) ? (string) $sla->getName() : null,
            'due' => $ticket->getEstDueDate() ? Format::datetime($ticket->getEstDueDate()) : null,
            'source' => (string) $ticket->getSource(),
            'created' => (string) $ticket->getCreateDate(),
            'url' => $this->ticketUrl($ticket),
            'links' => $links,
        );
    }

    protected function entryPayload(ThreadEntry $entry) {
        return array(
            'id' => $entry->getId(),
            'type' => $entry->getType(),
            'kind' => $this->entryTypeLabel($entry),
            'poster' => $this->posterName($entry),
            'source' => (string) $entry->getSource(),
            'title' => (string) $entry->getTitle(),
            'text' => $this->entryText($entry, 20000),
            'created' => (string) $entry->created,
        );
    }

    protected function deliver(ConnectorChannel $channel, $event, array $data, $objectType=null, $objectId=null) {
        global $cfg;
        $url = $channel->getSetting('url') ?: $this->connector->getSetting('url');
        if (!$url)
            return $this->skip($event, __('No outbound URL configured'), 'out');
        $payload = array_merge(array(
            'event' => $event,
            'timestamp' => date('c'),
            'helpdesk' => array('name' => (string) $cfg->getTitle(), 'url' => $cfg->getBaseUrl()),
            'channel' => array('key' => $channel->getChannelId(), 'name' => $channel->getName(),
                'department' => $channel->getDeptName()),
        ), $data);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = array(
            'Content-Type' => 'application/json; charset=utf-8',
            'X-OSTicket-Event' => $event,
            'X-OSTicket-Delivery' => uniqid('', true),
        );
        if (($secret = $this->connector->getSetting('secret')))
            $headers['X-OSTicket-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $r = HttpClient::request('POST', $url, array('headers' => $headers, 'body' => $body, 'timeout' => 10));
        if (!HttpClient::ok($r))
            return $this->fail(HttpClient::describe($r), $event, $objectType, $objectId, $channel);
        return $this->ok($event, sprintf('HTTP %d', $r['status']), $objectType, $objectId, null, $channel);
    }

    /**
     * "Test Connection": POST a ping event to the outbound URL (if any)
     */
    function test() {
        global $cfg;
        $url = $this->connector->getSetting('url');
        if (!$url)
            return array('ok' => true, 'message' => sprintf(__('No outbound URL configured. Inbound endpoint: %s'),
                $this->connector->getWebhookUrl()));
        $body = json_encode(array('event' => 'ping', 'connector' => $this->connector->getName(),
            'sent' => date('c'), 'helpdesk' => $cfg->getBaseUrl()));
        $headers = array('Content-Type' => 'application/json', 'X-OsTicket-Event' => 'ping');
        if (($secret = $this->connector->getSetting('secret')))
            $headers['X-OsTicket-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        $r = HttpClient::request('POST', $url, array('headers' => $headers, 'body' => $body, 'timeout' => 10));
        if (!HttpClient::ok($r))
            return array('ok' => false, 'message' => HttpClient::describe($r));
        return array('ok' => true, 'message' => sprintf(__('Ping delivered to %s (HTTP %d)'), $url, $r['status']));
    }

    function pushTicketCreated(Ticket $ticket, ConnectorChannel $channel) {
        if (!$this->wants('ticket.created'))
            return true;
        $data = array('ticket' => $this->ticketPayload($ticket));
        if (($m = $ticket->getLastMessage()))
            $data['message'] = $this->entryPayload($m);
        return $this->deliver($channel, 'ticket.created', $data, ConnectorLink::TYPE_TICKET, $ticket->getId());
    }

    function pushEntry(ThreadEntry $entry, Ticket $ticket, ConnectorChannel $channel) {
        $map = array('M' => 'ticket.message', 'R' => 'ticket.response', 'N' => 'ticket.note');
        $event = isset($map[$entry->getType()]) ? $map[$entry->getType()] : 'ticket.entry';
        if (!$this->wants($event))
            return true;
        return $this->deliver($channel, $event, array(
            'ticket' => $this->ticketPayload($ticket),
            'entry' => $this->entryPayload($entry),
        ), ConnectorLink::TYPE_ENTRY, $entry->getId());
    }

    function pushTicketEvent(Ticket $ticket, $event, array $data, ConnectorChannel $channel) {
        $name = 'ticket.' . $event;
        if (!$this->wants($name))
            return true;
        return $this->deliver($channel, $name, array(
            'ticket' => $this->ticketPayload($ticket),
            'data' => $data,
        ), ConnectorLink::TYPE_TICKET, $ticket->getId());
    }

    function sendAlert(ConnectorChannel $channel, array $alert) {
        if (!$this->wants('alert'))
            return true;
        $data = array('alert' => $alert);
        if (!empty($alert['ticket_id']) && ($t = Ticket::lookup((int) $alert['ticket_id'])))
            $data['ticket'] = $this->ticketPayload($t);
        return $this->deliver($channel, 'alert', $data,
            isset($alert['incident_id']) ? ConnectorLink::TYPE_INCIDENT : null,
            isset($alert['incident_id']) ? $alert['incident_id'] : null);
    }

    /* ------------------- Inbound ------------------- */

    function authenticate(ConnectorRequest $req) {
        $token = $this->connector->getSetting('inbound_token');
        $provided = $req->header('x-webhook-token') ?: (isset($req->query['token']) ? $req->query['token'] : null);
        if ($token && $provided && hash_equals($token, (string) $provided))
            return true;
        $secret = $this->connector->getSetting('secret');
        $sig = (string) $req->header('x-webhook-signature');
        if ($secret && $sig) {
            $sig = preg_replace('/^sha256=/i', '', trim($sig));
            if (hash_equals(hash_hmac('sha256', $req->raw, $secret), $sig))
                return true;
        }
        return false;
    }

    protected function json($status, array $data) {
        return array('status' => $status, 'json' => $data);
    }

    function handleInbound(ConnectorRequest $req) {
        if (!$this->authenticate($req)) {
            $this->connector->logEvent('in', 'request', 'error', __('Authentication failed'));
            return $this->json(401, array('ok' => false, 'error' => 'Authentication failed'));
        }
        $p = is_array($req->json) ? $req->json : array();
        $event = strtolower((string) (isset($p['event']) ? $p['event'] : 'ticket.create'));
        $key = isset($p['channel']) ? (string) $p['channel'] : '';
        $channel = $key ? ConnectorChannel::lookupByExternal($this->connector->getId(), $key) : null;
        if (!$channel) {
            $defaults = $this->connector->getDefaultChannels();
            $channel = $defaults ? $defaults[0] : null;
        }

        try {
            switch ($event) {
            case 'ticket.create':
            case 'ticket.created':
            case 'create':
                return $this->inboundCreate($p, $channel);
            case 'ticket.reply':
            case 'ticket.note':
            case 'ticket.message':
            case 'reply':
            case 'note':
                return $this->inboundPost($p, $event);
            case 'ticket.close':
            case 'ticket.reopen':
            case 'close':
            case 'reopen':
                return $this->inboundStatus($p, strpos($event, 'close') !== false ? 'close' : 'reopen');
            case 'ping':
                return $this->json(200, array('ok' => true, 'pong' => date('c')));
            }
        }
        catch (Throwable $t) {
            $this->connector->logEvent('in', $event, 'error', $t->getMessage());
            return $this->json(500, array('ok' => false, 'error' => 'Processing error'));
        }
        return $this->json(400, array('ok' => false, 'error' => sprintf('Unknown event "%s"', $event)));
    }

    protected function inboundCreate(array $p, $channel) {
        if (!$channel || !$channel->isInbound())
            return $this->json(400, array('ok' => false,
                'error' => 'No inbound channel for this request (set "channel" to a mapped routing key)'));

        if (!empty($p['external_id'])
                && ($link = ConnectorLink::byExternal($this->connector->getId(), $p['external_id'],
                    ConnectorLink::TYPE_TICKET))
                && ($ticket = Ticket::lookup((int) $link->getObjectId()))) {
            // Idempotent: same external id → existing ticket. Append the
            // new details as a message when provided.
            if (!empty($p['message'])) {
                $errors = array();
                ConnectorManager::postToTicket($this->connector, $ticket, array(
                    'body' => (string) $p['message'], 'name' => isset($p['name']) ? $p['name'] : '',
                    'mode' => 'message', 'external_id' => isset($p['entry_id']) ? $p['entry_id'] : null,
                ), $errors);
            }
            return $this->json(200, array('ok' => true, 'existing' => true,
                'ticket' => array('id' => $ticket->getId(), 'number' => $ticket->getNumber(),
                    'url' => $this->ticketUrl($ticket))));
        }

        $errors = array();
        $ticket = ConnectorManager::createTicket($this->connector, $channel, array(
            'subject' => isset($p['subject']) ? $p['subject'] : '',
            'message' => isset($p['message']) ? (string) $p['message'] : '',
            'name' => isset($p['name']) ? $p['name'] : '',
            'email' => isset($p['email']) ? $p['email'] : null,
            'priority' => isset($p['priority']) ? $p['priority'] : null,
            'topic_id' => isset($p['topic_id']) ? $p['topic_id'] : null,
            'external_id' => isset($p['external_id']) ? $p['external_id'] : null,
            'external_key' => isset($p['external_key']) ? $p['external_key'] : null,
            'external_url' => isset($p['external_url']) ? $p['external_url'] : null,
        ), $errors);
        if (!$ticket) {
            $this->connector->logEvent('in', 'ticket.create', 'error',
                Format::array_implode(': ', '; ', $errors), null, null,
                isset($p['external_id']) ? $p['external_id'] : null, $channel);
            return $this->json(422, array('ok' => false, 'error' => 'Unable to create ticket',
                'errors' => $errors));
        }
        $this->connector->logEvent('in', 'ticket.create', 'ok', sprintf('#%s', $ticket->getNumber()),
            ConnectorLink::TYPE_TICKET, $ticket->getId(),
            isset($p['external_id']) ? $p['external_id'] : null, $channel);
        return $this->json(201, array('ok' => true, 'ticket' => array(
            'id' => $ticket->getId(), 'number' => $ticket->getNumber(), 'url' => $this->ticketUrl($ticket),
            'department' => (string) $ticket->getDeptName())));
    }

    protected function inboundPost(array $p, $event) {
        $ref = isset($p['ticket']) ? $p['ticket'] : (isset($p['number']) ? $p['number']
            : (isset($p['external_id']) ? $p['external_id'] : ''));
        if (!($ticket = ConnectorManager::findTicket($ref, $this->connector)))
            return $this->json(404, array('ok' => false, 'error' => 'Ticket not found'));
        $mode = 'auto';
        if ($event == 'ticket.note' || $event == 'note' || !empty($p['internal']))
            $mode = 'note';
        elseif ($event == 'ticket.message')
            $mode = 'message';
        elseif (isset($p['mode']) && in_array($p['mode'], array('reply', 'note', 'message')))
            $mode = $p['mode'];
        $errors = array();
        $entry = ConnectorManager::postToTicket($this->connector, $ticket, array(
            'body' => isset($p['message']) ? (string) $p['message'] : '',
            'name' => isset($p['name']) ? $p['name'] : '',
            'email' => isset($p['email']) ? $p['email'] : null,
            'mode' => $mode,
            'external_id' => isset($p['entry_id']) ? $p['entry_id'] : null,
            'title' => isset($p['title']) ? $p['title'] : null,
        ), $errors);
        if (!$entry) {
            $this->connector->logEvent('in', $event, 'error', Format::array_implode(': ', '; ', $errors),
                ConnectorLink::TYPE_TICKET, $ticket->getId());
            return $this->json(422, array('ok' => false, 'error' => 'Unable to post', 'errors' => $errors));
        }
        $this->connector->logEvent('in', $event, 'ok', null, ConnectorLink::TYPE_ENTRY, $entry->getId(),
            isset($p['entry_id']) ? $p['entry_id'] : null);
        return $this->json(201, array('ok' => true, 'entry' => array('id' => $entry->getId(),
            'type' => $entry->getType()), 'ticket' => array('id' => $ticket->getId(),
            'number' => $ticket->getNumber())));
    }

    protected function inboundStatus(array $p, $action) {
        $ref = isset($p['ticket']) ? $p['ticket'] : (isset($p['number']) ? $p['number']
            : (isset($p['external_id']) ? $p['external_id'] : ''));
        if (!($ticket = ConnectorManager::findTicket($ref, $this->connector)))
            return $this->json(404, array('ok' => false, 'error' => 'Ticket not found'));
        $errors = array();
        $comment = isset($p['message']) ? (string) $p['message'] : '';
        ConnectorManager::beginInbound($this->connector, null, null);
        try {
            if ($action == 'close') {
                if (!$ticket->isOpen())
                    $ok = true;
                else
                    $ok = $ticket->setStatus(ConnectorManager::closedStatus(), $comment ?: sprintf(__('Closed via %s'),
                        $this->connector->getName()), $errors, false, true);
            }
            else {
                $ok = $ticket->isOpen() ? true : $ticket->reopen();
                if ($ok && $comment)
                    $ticket->logNote(__('Reopened'), $comment, $this->connector->getName(), false);
            }
        }
        finally {
            ConnectorManager::endInbound();
        }
        if (!$ok)
            return $this->json(422, array('ok' => false, 'error' => $errors['err'] ?? 'Unable to change status'));
        $this->connector->logEvent('in', 'ticket.'.$action, 'ok', null, ConnectorLink::TYPE_TICKET, $ticket->getId());
        return $this->json(200, array('ok' => true, 'ticket' => array('id' => $ticket->getId(),
            'number' => $ticket->getNumber(), 'status' => (string) $ticket->getStatus())));
    }
}
?>
