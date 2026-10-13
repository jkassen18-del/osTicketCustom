<?php
/*********************************************************************
    connectors/linear.php

    Linear connector: maps Linear teams to osTicket departments.

      * Issues created in a mapped Linear team become tickets in the
        mapped department (inbound webhook)
      * Comments on linked issues are posted to the ticket as agent
        replies (or internal notes) and vice-versa
      * New tickets in mapped departments create Linear issues
      * Closing / reopening is mirrored (workflow state of type
        "completed" / "unstarted")
      * Incident and SLA alerts become issues / comments

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

class LinearConnector extends ConnectorBackend {
    static $type = 'linear';
    static $name = /* @trans */ 'Linear';
    static $description = /* @trans */ 'Two-way sync between Linear teams and departments: issues become tickets, comments become replies, tickets become issues.';
    static $icon = 'random';

    const API_URL = 'https://api.linear.app/graphql';

    function getConfigFields() {
        global $cfg;
        return array(
            'api_key' => array(
                'label' => __('API key'),
                'type' => 'password',
                'required' => true,
                'hint' => __('Personal API key (Linear → Settings → API) or an OAuth access token with write access to the mapped teams.'),
            ),
            'webhook_secret' => array(
                'label' => __('Webhook signing secret'),
                'type' => 'password',
                'required' => false,
                'hint' => __('Secret shown when creating the webhook in Linear (Settings → API → Webhooks). Required to accept inbound events.'),
            ),
            'create_issues' => array(
                'label' => __('Create issues for new tickets'),
                'type' => 'boolean',
                'default' => 1,
                'hint' => __('Every new ticket in a mapped department creates an issue in the mapped Linear team.'),
            ),
            'comment_mode' => array(
                'label' => __('Linear comments are posted as'),
                'type' => 'select',
                'choices' => array(
                    'reply' => __('Replies to the ticket requester'),
                    'note' => __('Internal notes'),
                ),
                'default' => 'reply',
                'hint' => __('Start a comment with "/note" to keep it internal or "/reply" to send it to the requester regardless of this setting.'),
            ),
            'agent_id' => array(
                'label' => __('Reply as agent'),
                'type' => 'staff',
                'hint' => __('Agent used to attribute replies when the Linear user has no matching agent email. Falls back to the department manager.'),
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
                'hint' => __('Used as the ticket owner when a Linear issue creator has no (visible) email address. Use a mailbox that is not fetched by this help desk to avoid loops.'),
            ),
            'labels' => array(
                'label' => __('Labels for created issues'),
                'type' => 'text',
                'hint' => __('Comma separated Linear label names (optional, e.g. "support, customer").'),
            ),
            'autorespond' => array(
                'label' => __('Send new-ticket auto-responses'),
                'type' => 'boolean',
                'default' => 0,
            ),
        );
    }

    function getChannelIdLabel() {
        return __('Linear team (key or ID)');
    }

    function getChannelIdHint() {
        return __('The team key (e.g. ENG) or team UUID. Keys are resolved to IDs when the connector is online.');
    }

    function getChannelFields() {
        return array(
            'project_id' => array(
                'label' => __('Project ID'),
                'type' => 'text',
                'hint' => __('Optional: created issues are added to this Linear project.'),
            ),
            'topic_id' => array(
                'label' => __('Help topic'),
                'type' => 'topic',
                'hint' => __('Optional: help topic applied to tickets created from this team.'),
            ),
        );
    }

    function getSetupInstructions() {
        $url = $this->connector->getId() ? $this->connector->getWebhookUrl()
            : __('(save the connector first)');
        return sprintf(__(
            '<ol>'
            .'<li>In Linear open <b>Settings → API</b> and create a <b>Personal API key</b> (or an OAuth app token) with access to the teams you want to connect. Paste it above.</li>'
            .'<li>Still under <b>Settings → API → Webhooks</b>, create a webhook with URL <code>%s</code>, enable the <b>Issues</b> and <b>Comments</b> resources and copy the <b>signing secret</b> above.</li>'
            .'<li>Map each Linear team to a department in the <b>Channels</b> tab (use the team key, e.g. <code>ENG</code>).</li>'
            .'<li>Use <b>Test connection</b> to verify the API key and cache the team list.</li>'
            .'</ol>'), Format::htmlchars($url));
    }

    /* ------------------- GraphQL ------------------- */

    function gql($query, array $variables=array(), $timeout=10) {
        $key = $this->connector->getSetting('api_key');
        if (!$key) {
            $this->lastError = __('API key is not configured');
            return null;
        }
        $r = HttpClient::postJson(self::API_URL, array(
            'query' => $query, 'variables' => (object) $variables,
        ), array('Authorization' => $key), $timeout);
        if (!HttpClient::ok($r)) {
            $this->lastError = HttpClient::describe($r);
            return null;
        }
        if (!empty($r['json']['errors'])) {
            $msgs = array();
            foreach ($r['json']['errors'] as $e)
                $msgs[] = isset($e['message']) ? $e['message'] : json_encode($e);
            $this->lastError = implode('; ', $msgs);
            return null;
        }
        return isset($r['json']['data']) ? $r['json']['data'] : array();
    }

    function test() {
        $data = $this->gql('query { viewer { id name email } teams { nodes { id key name } } }');
        if ($data === null)
            return array('ok' => false, 'message' => $this->lastError);
        $teams = array();
        foreach ($data['teams']['nodes'] as $t)
            $teams[$t['id']] = array('key' => $t['key'], 'name' => $t['name']);
        $this->connector->setSetting('_bot_user_id', $data['viewer']['id']);
        $this->connector->setSetting('_teams', $teams);
        $this->connector->touch(null);
        return array('ok' => true, 'message' => sprintf(
            __('Connected to Linear as %s. %d teams visible: %s'),
            $data['viewer']['name'], count($teams),
            implode(', ', array_map(function($t) { return $t['key']; }, $teams))));
    }

    function validateChannel(&$vars, &$extra, &$errors) {
        // Resolve a team key to the team id when possible
        $id = $vars['channel_id'];
        if (!$id)
            return true;
        if (preg_match('/^[0-9a-f-]{36}$/i', $id))
            return true;
        $teams = $this->connector->getSetting('_teams', array());
        if (!$teams && $this->connector->getSetting('api_key')) {
            $this->test();
            $teams = $this->connector->getSetting('_teams', array());
        }
        foreach ($teams as $tid => $t) {
            if (!strcasecmp($t['key'], $id)) {
                $vars['channel_id'] = $tid;
                if (!$vars['name'])
                    $vars['name'] = sprintf('%s (%s)', $t['name'], $t['key']);
                return true;
            }
        }
        if ($teams)
            $errors['channel_id'] = sprintf(__('Unknown Linear team "%s". Known teams: %s'),
                $id, implode(', ', array_map(function($t) { return $t['key']; }, $teams)));
        return !$errors;
    }

    protected function teamLabel($teamId) {
        $teams = $this->connector->getSetting('_teams', array());
        if (isset($teams[$teamId]))
            return sprintf('%s (%s)', $teams[$teamId]['name'], $teams[$teamId]['key']);
        return $teamId;
    }

    /**
     * Workflow state id of the given type (completed, unstarted, …) for a
     * team. Cached in the connector configuration for a day.
     */
    protected function stateId($teamId, $type) {
        $cache = $this->connector->getSetting('_states', array());
        if (!isset($cache[$teamId]) || (time() - (int) $cache[$teamId]['ts']) > 86400) {
            $data = $this->gql('query($id: String!) { team(id: $id) { states { nodes { id name type position } } } }',
                array('id' => $teamId));
            if ($data === null)
                return null;
            $states = array();
            foreach ($data['team']['states']['nodes'] as $s)
                $states[] = array('id' => $s['id'], 'type' => $s['type'],
                    'name' => $s['name'], 'position' => (float) $s['position']);
            usort($states, function($a, $b) { return $a['position'] <=> $b['position']; });
            $cache[$teamId] = array('ts' => time(), 'states' => $states);
            $this->connector->setSetting('_states', $cache);
            $this->connector->save();
        }
        foreach ($cache[$teamId]['states'] as $s)
            if ($s['type'] == $type)
                return $s['id'];
        return null;
    }

    protected function labelIds($teamId) {
        $names = array_filter(array_map('trim', explode(',', (string) $this->connector->getSetting('labels'))));
        if (!$names)
            return array();
        $cache = $this->connector->getSetting('_labels', array());
        if (!isset($cache[$teamId]) || (time() - (int) $cache[$teamId]['ts']) > 86400) {
            $data = $this->gql('query($id: String!) { team(id: $id) { labels { nodes { id name } } } }',
                array('id' => $teamId));
            $labels = array();
            if ($data !== null)
                foreach ($data['team']['labels']['nodes'] as $l)
                    $labels[strtolower($l['name'])] = $l['id'];
            $cache[$teamId] = array('ts' => time(), 'labels' => $labels);
            $this->connector->setSetting('_labels', $cache);
            $this->connector->save();
        }
        $ids = array();
        foreach ($names as $n)
            if (isset($cache[$teamId]['labels'][strtolower($n)]))
                $ids[] = $cache[$teamId]['labels'][strtolower($n)];
        return $ids;
    }

    protected function lookupUser($userId) {
        if (!$userId)
            return null;
        $data = $this->gql('query($id: String!) { user(id: $id) { id name displayName email } }',
            array('id' => $userId));
        return $data && isset($data['user']) ? $data['user'] : null;
    }

    protected function markdownToText($md) {
        $text = (string) $md;
        $text = preg_replace('/!\[[^\]]*\]\(([^)]+)\)/', '$1', $text);
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text);
        $text = preg_replace('/^#{1,6}\s*/m', '', $text);
        $text = preg_replace('/(\*\*|__)(.*?)\1/s', '$2', $text);
        return trim($text);
    }

    protected function escapeMd($text) {
        return preg_replace('/([*_`~#>\[\]])/', '\\\\$1', (string) $text);
    }

    /* ------------------- Outbound ------------------- */

    function createIssue(Ticket $ticket, ConnectorChannel $channel) {
        $teamId = $channel->getChannelId();
        $owner = $ticket->getOwner();
        $lines = array();
        $lines[] = sprintf('**%s** %s', sprintf(__('Ticket #%s'), $ticket->getNumber()),
            sprintf(__('opened by %s <%s>'), $this->escapeMd($owner ? $owner->getName() : ''),
                $ticket->getEmail()));
        $facts = array();
        foreach ($this->ticketFacts($ticket) as $f)
            if ($f[0] != __('From'))
                $facts[] = sprintf('%s: %s', $f[0], $this->escapeMd($f[1]));
        $lines[] = implode(' · ', $facts);
        $lines[] = sprintf('[%s](%s)', __('Open in osTicket'), $this->ticketUrl($ticket));
        $lines[] = '';
        $lines[] = '---';
        $lines[] = '';
        $lines[] = $this->firstMessageText($ticket, 6000);

        $input = array(
            'teamId' => $teamId,
            'title' => mb_substr($this->ticketTitle($ticket), 0, 250),
            'description' => implode("\n", $lines),
            'priority' => $this->priorityUrgency($ticket),
        );
        if (($pid = $channel->getSetting('project_id')))
            $input['projectId'] = $pid;
        if (($labels = $this->labelIds($teamId)))
            $input['labelIds'] = $labels;

        $data = $this->gql('mutation($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id identifier url } } }',
            array('input' => $input));
        if ($data === null || empty($data['issueCreate']['success']))
            return $this->fail($this->lastError ?: __('Linear rejected the issue'),
                'ticket.created', ConnectorLink::TYPE_TICKET, $ticket->getId(), $channel);

        $issue = $data['issueCreate']['issue'];
        $link = ConnectorLink::record($this->connector, ConnectorLink::TYPE_TICKET,
            $ticket->getId(), $issue['id'], array(
                'channel' => $channel, 'key' => $issue['identifier'], 'url' => $issue['url'],
                'extra' => array('team_id' => $teamId)));
        $this->ok('ticket.created', sprintf(__('Created %s in %s'), $issue['identifier'],
            $this->teamLabel($teamId)), ConnectorLink::TYPE_TICKET, $ticket->getId(),
            $issue['id'], $channel);
        return $link;
    }

    function pushTicketCreated(Ticket $ticket, ConnectorChannel $channel) {
        if (!$this->connector->getSetting('create_issues', 1))
            return $this->skip('ticket.created', __('Issue creation disabled'), 'out');
        return (bool) $this->createIssue($ticket, $channel);
    }

    function pushEntry(ThreadEntry $entry, Ticket $ticket, ConnectorChannel $channel) {
        $link = ConnectorLink::forObject($this->connector->getId(), ConnectorLink::TYPE_TICKET,
            $ticket->getId());
        if (!$link) {
            if (!$this->connector->getSetting('create_issues', 1))
                return $this->skip('entry.created', __('Ticket is not linked to a Linear issue'), 'out');
            if (!($link = $this->createIssue($ticket, $channel)))
                return false;
        }
        $body = sprintf("**%s** — %s %s\n\n%s",
            $this->escapeMd($this->posterName($entry)),
            $this->entryTypeLabel($entry),
            sprintf(__('via osTicket ticket [#%s](%s)'), $ticket->getNumber(), $this->ticketUrl($ticket)),
            $this->entryText($entry, 8000));
        $data = $this->gql('mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success comment { id url } } }',
            array('input' => array('issueId' => $link->getExternalId(), 'body' => $body)));
        if ($data === null || empty($data['commentCreate']['success']))
            return $this->fail($this->lastError ?: __('Linear rejected the comment'),
                'entry.created', ConnectorLink::TYPE_ENTRY, $entry->getId(), $channel);
        $comment = $data['commentCreate']['comment'];
        ConnectorLink::record($this->connector, ConnectorLink::TYPE_ENTRY, $entry->getId(),
            $comment['id'], array('channel' => $channel, 'url' => isset($comment['url']) ? $comment['url'] : null));
        return $this->ok('entry.created', sprintf(__('Comment added to %s'), $link->getLabel()),
            ConnectorLink::TYPE_ENTRY, $entry->getId(), $comment['id'], $channel);
    }

    function pushTicketEvent(Ticket $ticket, $event, array $data, ConnectorChannel $channel) {
        $link = ConnectorLink::forObject($this->connector->getId(), ConnectorLink::TYPE_TICKET,
            $ticket->getId());
        if (!$link)
            return $this->skip('ticket.'.$event, __('Ticket is not linked to a Linear issue'), 'out');

        $extra = $link->getExtra();
        $teamId = isset($extra['team_id']) ? $extra['team_id'] : $channel->getChannelId();
        if (in_array($event, array('closed', 'reopened'))) {
            $stateId = $this->stateId($teamId, $event == 'closed' ? 'completed' : 'unstarted');
            if (!$stateId)
                return $this->fail($this->lastError ?: __('No matching workflow state found'),
                    'ticket.'.$event, ConnectorLink::TYPE_TICKET, $ticket->getId(), $channel);
            $resp = $this->gql('mutation($id: String!, $input: IssueUpdateInput!) { issueUpdate(id: $id, input: $input) { success } }',
                array('id' => $link->getExternalId(), 'input' => array('stateId' => $stateId)));
            if ($resp === null || empty($resp['issueUpdate']['success']))
                return $this->fail($this->lastError ?: __('Linear rejected the update'),
                    'ticket.'.$event, ConnectorLink::TYPE_TICKET, $ticket->getId(), $channel);
        }
        $body = sprintf("_%s_ — [%s](%s)", $this->escapeMd($this->eventTitle($ticket, $event, $data)),
            __('osTicket'), $this->ticketUrl($ticket));
        $this->gql('mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success } }',
            array('input' => array('issueId' => $link->getExternalId(), 'body' => $body)));
        return $this->ok('ticket.'.$event, $link->getLabel(), ConnectorLink::TYPE_TICKET,
            $ticket->getId(), $link->getExternalId(), $channel);
    }

    function sendAlert(ConnectorChannel $channel, array $alert) {
        $kind = isset($alert['kind']) ? $alert['kind'] : 'system';
        $title = isset($alert['title']) ? $alert['title'] : __('Alert');
        $text = isset($alert['text']) ? $alert['text'] : '';
        $facts = array();
        if (!empty($alert['facts']))
            foreach ($alert['facts'] as $f)
                $facts[] = sprintf('- **%s:** %s', $this->escapeMd($f[0]), $this->escapeMd($f[1]));
        $body = trim(sprintf("%s\n\n%s%s", $this->escapeMd($text), implode("\n", $facts),
            !empty($alert['url']) ? sprintf("\n\n[%s](%s)", __('Open'), $alert['url']) : ''));

        if ($kind == 'incident' && !empty($alert['incident_id'])) {
            $link = ConnectorLink::forObject($this->connector->getId(),
                ConnectorLink::TYPE_INCIDENT, $alert['incident_id']);
            $event = isset($alert['event']) ? $alert['event'] : 'open';
            if (!$link) {
                if ($event == 'resolved')
                    return $this->skip('alert.incident', __('Resolved incident without an issue'), 'out');
                $input = array(
                    'teamId' => $channel->getChannelId(),
                    'title' => mb_substr(sprintf('[%s] %s', strtoupper((string) $alert['severity']), $title), 0, 250),
                    'description' => $body,
                    'priority' => $this->severityUrgency(isset($alert['severity']) ? $alert['severity'] : 'warning'),
                );
                $data = $this->gql('mutation($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id identifier url } } }',
                    array('input' => $input));
                if ($data === null || empty($data['issueCreate']['success']))
                    return $this->fail($this->lastError ?: __('Linear rejected the issue'), 'alert.incident',
                        ConnectorLink::TYPE_INCIDENT, $alert['incident_id'], $channel);
                $issue = $data['issueCreate']['issue'];
                ConnectorLink::record($this->connector, ConnectorLink::TYPE_INCIDENT,
                    $alert['incident_id'], $issue['id'], array('channel' => $channel,
                        'key' => $issue['identifier'], 'url' => $issue['url'],
                        'extra' => array('team_id' => $channel->getChannelId())));
                return $this->ok('alert.incident', sprintf(__('Created %s'), $issue['identifier']),
                    ConnectorLink::TYPE_INCIDENT, $alert['incident_id'], $issue['id'], $channel);
            }
            $resp = $this->gql('mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success } }',
                array('input' => array('issueId' => $link->getExternalId(),
                    'body' => sprintf("**%s**\n\n%s", $this->escapeMd($title), $body))));
            if ($resp === null)
                return $this->fail($this->lastError, 'alert.incident', ConnectorLink::TYPE_INCIDENT,
                    $alert['incident_id'], $channel);
            if ($event == 'resolved' && $this->connector->syncsStatus()) {
                $extra = $link->getExtra();
                $teamId = isset($extra['team_id']) ? $extra['team_id'] : $channel->getChannelId();
                if (($stateId = $this->stateId($teamId, 'completed')))
                    $this->gql('mutation($id: String!, $input: IssueUpdateInput!) { issueUpdate(id: $id, input: $input) { success } }',
                        array('id' => $link->getExternalId(), 'input' => array('stateId' => $stateId)));
            }
            return $this->ok('alert.incident', $link->getLabel(), ConnectorLink::TYPE_INCIDENT,
                $alert['incident_id'], $link->getExternalId(), $channel);
        }

        // SLA / ticket related alerts become comments on the linked issue
        if (!empty($alert['ticket_id'])) {
            $link = ConnectorLink::forObject($this->connector->getId(), ConnectorLink::TYPE_TICKET,
                $alert['ticket_id']);
            if (!$link)
                return $this->skip('alert.'.$kind, __('Ticket is not linked to a Linear issue'), 'out');
            $resp = $this->gql('mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success } }',
                array('input' => array('issueId' => $link->getExternalId(),
                    'body' => sprintf("**%s**\n\n%s", $this->escapeMd($title), $body))));
            if ($resp === null)
                return $this->fail($this->lastError, 'alert.'.$kind, ConnectorLink::TYPE_TICKET,
                    $alert['ticket_id'], $channel);
            return $this->ok('alert.'.$kind, $link->getLabel(), ConnectorLink::TYPE_TICKET,
                $alert['ticket_id'], $link->getExternalId(), $channel);
        }
        return $this->skip('alert.'.$kind, __('Nothing to post'), 'out');
    }

    protected function severityUrgency($severity) {
        switch (strtolower((string) $severity)) {
        case 'critical': return 1;
        case 'error': return 2;
        case 'warning': return 3;
        }
        return 4;
    }

    /* ------------------- Inbound ------------------- */

    function verifySignature(ConnectorRequest $req) {
        $secret = $this->connector->getSetting('webhook_secret');
        if (!$secret)
            return false;
        $sig = $req->header('linear-signature');
        if (!$sig)
            return false;
        $expected = hash_hmac('sha256', $req->raw, $secret);
        return hash_equals($expected, $sig);
    }

    function handleInbound(ConnectorRequest $req) {
        if (!$this->verifySignature($req)) {
            $this->connector->logEvent('in', 'webhook', 'error', __('Invalid or missing signature'));
            return array('status' => 401, 'text' => 'Invalid signature');
        }
        $payload = $req->json;
        if (!is_array($payload) || !isset($payload['type']))
            return array('status' => 400, 'text' => 'Unsupported payload');

        $type = $payload['type'];
        $action = isset($payload['action']) ? $payload['action'] : '';
        $data = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

        try {
            if ($type == 'Issue' && $action == 'create')
                $result = $this->onIssueCreated($data, $payload);
            elseif ($type == 'Issue' && $action == 'update')
                $result = $this->onIssueUpdated($data, $payload);
            elseif ($type == 'Comment' && $action == 'create')
                $result = $this->onCommentCreated($data, $payload);
            else
                $result = $this->skip('webhook', sprintf('%s/%s ignored', $type, $action));
        }
        catch (Throwable $t) {
            $this->connector->logEvent('in', strtolower($type.'.'.$action), 'error', $t->getMessage());
            return array('status' => 500, 'text' => 'Processing error');
        }
        return array('status' => 200, 'json' => array('ok' => (bool) $result));
    }

    protected function onIssueCreated(array $data, array $payload) {
        $teamId = isset($data['teamId']) ? $data['teamId']
            : (isset($data['team']['id']) ? $data['team']['id'] : null);
        $issueId = isset($data['id']) ? $data['id'] : null;
        if (!$teamId || !$issueId)
            return $this->skip('issue.create', __('Incomplete issue payload'));

        if (ConnectorLink::byExternal($this->connector->getId(), $issueId, ConnectorLink::TYPE_TICKET))
            return $this->skip('issue.create', sprintf(__('%s already linked'), $data['identifier'] ?? $issueId));

        $channel = ConnectorChannel::lookupByExternal($this->connector->getId(), $teamId);
        if (!$channel && isset($data['team']['key']))
            $channel = ConnectorChannel::lookupByExternal($this->connector->getId(), $data['team']['key']);
        if (!$channel || !$channel->isInbound() || !$channel->isEnabled())
            return $this->skip('issue.create', sprintf(__('Team %s is not mapped for inbound tickets'),
                $this->teamLabel($teamId)));

        $creator = null;
        if (!empty($data['creatorId']))
            $creator = $this->lookupUser($data['creatorId']);
        $name = $creator ? ($creator['name'] ?: $creator['displayName']) : __('Linear');
        $email = $creator && !empty($creator['email']) ? $creator['email'] : null;

        $identifier = isset($data['identifier']) ? $data['identifier'] : '';
        $url = isset($data['url']) ? $data['url'] : (isset($payload['url']) ? $payload['url'] : null);
        $priority = isset($data['priority']) ? (int) $data['priority'] : 0;

        $errors = array();
        $ticket = ConnectorManager::createTicket($this->connector, $channel, array(
            'subject' => ($identifier ? "[$identifier] " : '') . (isset($data['title']) ? $data['title'] : ''),
            'message' => $this->markdownToText(isset($data['description']) ? $data['description'] : ''),
            'attribution' => sprintf(__('Reported by %s via Linear issue %s'), $name, $identifier ?: $issueId)
                . ($url ? "\n$url" : ''),
            'name' => $name,
            'email' => $email,
            'priority' => $priority >= 1 && $priority <= 4 ? $priority : null,
            'external_id' => $issueId,
            'external_key' => $identifier,
            'external_url' => $url,
        ), $errors);
        if (!$ticket) {
            $this->connector->logEvent('in', 'issue.create', 'error',
                Format::array_implode(': ', '; ', $errors), null, null, $issueId, $channel);
            return false;
        }
        $link = ConnectorLink::forObject($this->connector->getId(), ConnectorLink::TYPE_TICKET, $ticket->getId());
        if ($link) {
            $link->setExtra(array_merge($link->getExtra(), array('team_id' => $teamId)));
            $link->save();
        }

        // Let the Linear side know the ticket number
        $this->gql('mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success } }',
            array('input' => array('issueId' => $issueId, 'body' => sprintf(
                __('Tracked as osTicket ticket [#%s](%s) in %s.'),
                $ticket->getNumber(), $this->ticketUrl($ticket), $this->escapeMd($ticket->getDeptName())))));

        return $this->ok('issue.create', sprintf(__('Created ticket #%s from %s'), $ticket->getNumber(),
            $identifier ?: $issueId), ConnectorLink::TYPE_TICKET, $ticket->getId(), $issueId, $channel, 'in');
    }

    protected function onIssueUpdated(array $data, array $payload) {
        $issueId = isset($data['id']) ? $data['id'] : null;
        if (!$issueId)
            return false;
        $link = ConnectorLink::byExternal($this->connector->getId(), $issueId, ConnectorLink::TYPE_TICKET);
        if (!$link)
            return $this->skip('issue.update', __('Issue is not linked'));
        $updated = isset($payload['updatedFrom']) && is_array($payload['updatedFrom'])
            ? $payload['updatedFrom'] : array();
        if (!array_key_exists('stateId', $updated) || !$this->connector->syncsStatus())
            return $this->skip('issue.update', __('No state change to mirror'));

        $ticket = Ticket::lookup((int) $link->getObjectId());
        if (!$ticket)
            return $this->skip('issue.update', __('Ticket no longer exists'));

        $stateType = isset($data['state']['type']) ? $data['state']['type'] : '';
        $stateName = isset($data['state']['name']) ? $data['state']['name'] : $stateType;
        $errors = array();
        ConnectorManager::beginInbound($this->connector, null, null);
        try {
            if (in_array($stateType, array('completed', 'canceled')) && $ticket->isOpen()) {
                if ($ticket->setStatus(ConnectorManager::closedStatus(), sprintf(__('Closed from Linear (%s: %s)'),
                        $link->getLabel(), $stateName), $errors, false, true))
                    return $this->ok('issue.update', sprintf(__('Ticket #%s closed'), $ticket->getNumber()),
                        ConnectorLink::TYPE_TICKET, $ticket->getId(), $issueId, null, 'in');
            }
            elseif (in_array($stateType, array('unstarted', 'started', 'backlog', 'triage'))
                    && $ticket->isClosed() && $ticket->isReopenable()) {
                if ($ticket->reopen()) {
                    $ticket->logNote(__('Reopened from Linear'), sprintf('%s: %s', $link->getLabel(), $stateName),
                        'Linear', false);
                    return $this->ok('issue.update', sprintf(__('Ticket #%s reopened'), $ticket->getNumber()),
                        ConnectorLink::TYPE_TICKET, $ticket->getId(), $issueId, null, 'in');
                }
            }
        }
        finally {
            ConnectorManager::endInbound();
        }
        return $this->skip('issue.update', sprintf(__('State "%s" needs no mirroring'), $stateName));
    }

    protected function onCommentCreated(array $data, array $payload) {
        $commentId = isset($data['id']) ? $data['id'] : null;
        $issueId = isset($data['issueId']) ? $data['issueId']
            : (isset($data['issue']['id']) ? $data['issue']['id'] : null);
        if (!$commentId || !$issueId)
            return $this->skip('comment.create', __('Incomplete comment payload'));

        if (ConnectorLink::byExternal($this->connector->getId(), $commentId, ConnectorLink::TYPE_ENTRY))
            return $this->skip('comment.create', __('Comment originated from osTicket'));

        $userId = isset($data['userId']) ? $data['userId']
            : (isset($data['user']['id']) ? $data['user']['id'] : null);
        if ($userId && $userId == $this->connector->getSetting('_bot_user_id'))
            return $this->skip('comment.create', __('Comment posted by the integration user'));

        $link = ConnectorLink::byExternal($this->connector->getId(), $issueId, ConnectorLink::TYPE_TICKET);
        if (!$link)
            return $this->skip('comment.create', __('Issue is not linked to a ticket'));
        $ticket = Ticket::lookup((int) $link->getObjectId());
        if (!$ticket)
            return $this->skip('comment.create', __('Ticket no longer exists'));

        $author = isset($data['user']) && is_array($data['user']) ? $data['user'] : null;
        if ((!$author || empty($author['email'])) && $userId)
            $author = $this->lookupUser($userId) ?: $author;
        $name = $author ? ($author['name'] ?: ($author['displayName'] ?? '')) : __('Linear user');
        $email = $author && !empty($author['email']) ? $author['email'] : null;

        $errors = array();
        $entry = ConnectorManager::postToTicket($this->connector, $ticket, array(
            'body' => $this->markdownToText(isset($data['body']) ? $data['body'] : ''),
            'name' => $name,
            'email' => $email,
            'mode' => 'auto',
            'external_id' => $commentId,
            'title' => sprintf(__('Comment on %s'), $link->getLabel()),
        ), $errors);
        if (!$entry) {
            $this->connector->logEvent('in', 'comment.create', 'error',
                Format::array_implode(': ', '; ', $errors), ConnectorLink::TYPE_TICKET,
                $ticket->getId(), $commentId);
            return false;
        }
        return $this->ok('comment.create', sprintf(__('%s posted on ticket #%s'),
            $this->entryTypeLabel($entry), $ticket->getNumber()), ConnectorLink::TYPE_ENTRY,
            $entry->getId(), $commentId, null, 'in');
    }
}
?>
