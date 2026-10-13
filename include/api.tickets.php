<?php

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';

class TicketApiController extends ApiController {

    # Supported arguments -- anything else is an error. These items will be
    # inspected _after_ the fixup() method of the ApiXxxDataParser classes
    # so that all supported input formats should be supported
    function getRequestStructure($format, $data=null) {
        $supported = array(
            "alert", "autorespond", "source", "topicId",
            "attachments" => array("*" =>
                array("name", "type", "data", "encoding", "size")
            ),
            "message", "ip", "priorityId",
            "system_emails" => array(
                "*" => "*"
            ),
            "thread_entry_recipients" => array (
                "*" => array("to", "cc")
            )
        );
        # Fetch dynamic form field names for the given help topic and add
        # the names to the supported request structure
        if (isset($data['topicId'])
                && ($topic = Topic::lookup($data['topicId']))
                && ($forms = $topic->getForms())) {
            foreach ($forms as $form)
                foreach ($form->getDynamicFields() as $field)
                    $supported[] = $field->get('name');
        }

        # Ticket form fields
        # TODO: Support userId for existing user
        if(($form = TicketForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        # User form fields
        if(($form = UserForm::getInstance()))
            foreach ($form->getFields() as $field)
                $supported[] = $field->get('name');

        switch ($format) {
            case 'email':
                $supported = array_merge($supported, [
                    'header', 'mid', 'emailId', 'to-email-id', 'ticketId', 'reply-to',
                    'reply-to-name', 'in-reply-to', 'references', 'thread-type', 'system_emails',
                    'mailflags' => ['bounce', 'auto-reply', 'spam', 'viral'],
                    'recipients' => ['*' => ['name', 'email', 'source']]
                ]);
                $supported['attachments']['*'][] = 'cid';
                break;
            case 'json':
            case 'xml':
                $supported = array_merge($supported, [
                    'duedate', 'slaId', 'staffId'
                ]);
                break;
        }

        return $supported;
    }

    /*
     Validate data - overwrites parent's validator for additional validations.
    */
    function validate(&$data, $format, $strict=true) {
        global $ost;

        //Call parent to Validate the structure
        if(!parent::validate($data, $format, $strict) && $strict)
            $this->exerr(400, __('Unexpected or invalid data received'));

        // Use the settings on the thread entry on the ticket details
        // form to validate the attachments in the email
        $tform = TicketForm::objects()->one()->getForm();
        $messageField = $tform->getField('message');
        $fileField = $messageField->getWidget()->getAttachments();

        // Nuke attachments IF API files are not allowed.
        if (!$messageField->isAttachmentsEnabled())
            $data['attachments'] = array();

        //Validate attachments: Do error checking... soft fail - set the error and pass on the request.
        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach($data['attachments'] as &$file) {
                if ($file['encoding'] && !strcasecmp($file['encoding'], 'base64')) {
                    if(!($file['data'] = base64_decode($file['data'], true)))
                        $file['error'] = sprintf(__('%s: Poorly encoded base64 data'),
                            Format::htmlchars($file['name']));
                }
                // Validate and save immediately
                try {
                    $F = $fileField->uploadAttachment($file);
                    $file['id'] = $F->getId();
                }
                catch (FileUploadError $ex) {
                    $name = $file['name'];
                    $file = array();
                    $file['error'] = Format::htmlchars($name) . ': ' . $ex->getMessage();
                }
            }
            unset($file);
        }

        return true;
    }


    function create($format) {

        if (!($key=$this->requireApiKey()) || !$key->canCreateTickets())
            return $this->exerr(401, __('API key not authorized'));

        $ticket = null;
        if (!strcasecmp($format, 'email')) {
            // Process remotely piped emails - could be a reply...etc.
            $ticket = $this->processEmailRequest();
        } else {
            // Get and Parse request body data for the format
            $ticket = $this->createTicket($this->getRequest($format));
        }



        if ($ticket)
            $this->response(201, $ticket->getNumber());
        else
            $this->exerr(500, _S("unknown error"));

    }

    /* ---------------- Read / reply API (osTicketCustom) ---------------- */

    /**
     * GET /api/tickets.json?status=open|closed|all&dept=<id|name>&email=…
     *        &q=<subject keyword>&since=<datetime>&limit=25&page=1
     */
    function search($format) {
        if (!($key=$this->requireApiKey()) || !$key->canReadTickets())
            return $this->exerr(401, __('API key not authorized to read tickets'));

        $q = Ticket::objects()->select_related('status', 'dept');
        $status = isset($_GET['status']) ? strtolower($_GET['status']) : 'open';
        if ($status && $status != 'all')
            $q = $q->filter(array('status__state' => $status == 'closed' ? 'closed' : 'open'));
        if (!empty($_GET['dept'])) {
            $dept = is_numeric($_GET['dept']) ? (int) $_GET['dept'] : Dept::getIdByName($_GET['dept']);
            $q = $q->filter(array('dept_id' => (int) $dept));
        }
        if (!empty($_GET['email']))
            $q = $q->filter(array('user__emails__address' => $_GET['email']));
        if (!empty($_GET['number']))
            $q = $q->filter(array('number' => $_GET['number']));
        if (!empty($_GET['q']))
            $q = $q->filter(array('cdata__subject__contains' => $_GET['q']));
        if (!empty($_GET['since']) && ($ts = strtotime($_GET['since'])))
            $q = $q->filter(array('lastupdate__gte' => date('Y-m-d H:i:s', Misc::dbtime($ts))));
        if (!empty($_GET['assigned'])) {
            if ($_GET['assigned'] == 'none')
                $q = $q->filter(array('staff_id' => 0, 'team_id' => 0));
            elseif (is_numeric($_GET['assigned']))
                $q = $q->filter(array('staff_id' => (int) $_GET['assigned']));
        }
        $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 25;
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $sort = isset($_GET['sort']) && $_GET['sort'] == 'created' ? '-created' : '-lastupdate';
        $total = $q->count();
        $q = $q->order_by($sort)->limit($limit)->offset(($page - 1) * $limit);

        $tickets = array();
        foreach ($q as $ticket)
            $tickets[] = $this->ticketSummary($ticket);
        $this->emit($format, 200, array('ok' => true, 'total' => $total, 'page' => $page,
            'limit' => $limit, 'tickets' => $tickets));
    }

    /**
     * GET /api/tickets/<number>.json — ticket details and thread
     */
    function get($number, $format) {
        if (!($key=$this->requireApiKey()) || !$key->canReadTickets())
            return $this->exerr(401, __('API key not authorized to read tickets'));
        if (!($ticket = $this->findTicket($number)))
            return $this->exerr(404, __('Ticket not found'));

        $data = $this->ticketSummary($ticket, true);
        $entries = array();
        $types = array('M', 'R');
        if (!isset($_GET['notes']) || $_GET['notes'])
            $types[] = 'N';
        if (($thread = $ticket->getThread())) {
            foreach ($thread->getEntries()->filter(array('type__in' => $types))->order_by('id') as $entry) {
                $entries[] = $this->entrySummary($entry);
            }
        }
        $data['thread'] = $entries;
        $this->emit($format, 200, array('ok' => true, 'ticket' => $data));
    }

    /**
     * POST /api/tickets/<number>/<reply|note|message|close|reopen|status>.json
     *
     * Body: {"message": "…", "name": "…", "email": "agent@…", "status": "closed", "alert": true}
     */
    function act($number, $action, $format) {
        if (!($key=$this->requireApiKey()) || !$key->canReplyTickets())
            return $this->exerr(401, __('API key not authorized to update tickets'));
        if (!($ticket = $this->findTicket($number)))
            return $this->exerr(404, __('Ticket not found'));

        $data = $this->getRequest($format, false) ?: array();
        $message = isset($data['message']) ? $data['message'] : '';
        if ($message instanceof ThreadEntryBody)
            $message = $message->getClean();
        $message = trim((string) $message);
        $name = isset($data['name']) ? Format::sanitize($data['name']) : '';
        $email = isset($data['email']) && Validator::is_email($data['email']) ? $data['email'] : null;
        $alert = isset($data['alert']) ? (bool) $data['alert'] : true;
        $errors = array();
        $entry = null;

        switch (strtolower($action)) {
        case 'reply':
            if (!$message)
                return $this->exerr(400, __('Message content is required'));
            $agent = null;
            if ($email)
                $agent = Staff::objects()->filter(array('email' => $email))->first();
            if (!$agent && !empty($data['staff_id']))
                $agent = Staff::lookup((int) $data['staff_id']);
            if (!$agent && ($dept = $ticket->getDept()))
                $agent = $dept->getManager();
            if (!$agent)
                return $this->exerr(400, __('No agent to attribute the reply to (send "email" of an agent or "staff_id")'));
            $vars = array(
                'staffId' => $agent->getId(),
                'poster' => $agent,
                'response' => new TextThreadEntryBody($message),
                'reply-to' => 'all',
                'source' => 'API',
            );
            if (!empty($data['status']) && ($st = $this->statusByName($data['status'])))
                $vars['reply_status_id'] = $st->getId();
            $entry = $ticket->postReply($vars, $errors, $alert, false);
            break;
        case 'note':
            if (!$message)
                return $this->exerr(400, __('Message content is required'));
            $poster = $name ?: 'API';
            if ($email && ($agent = Staff::objects()->filter(array('email' => $email))->first()))
                $poster = $agent;
            $vars = array(
                'note' => new TextThreadEntryBody($message),
                'title' => isset($data['title']) ? Format::sanitize($data['title']) : __('Note via API'),
                'source' => 'API',
            );
            $entry = $ticket->postNote($vars, $errors, $poster, $alert);
            break;
        case 'message':
            if (!$message)
                return $this->exerr(400, __('Message content is required'));
            $vars = array(
                'userId' => $ticket->getUserId(),
                'poster' => $name ?: (string) $ticket->getName(),
                'message' => new TextThreadEntryBody($message),
                'source' => 'API',
            );
            $entry = $ticket->postMessage($vars, 'API', $alert);
            break;
        case 'close':
        case 'reopen':
        case 'status':
            $a = strtolower($action);
            if ($a == 'status')
                $target = isset($data['status']) ? $data['status'] : '';
            elseif ($a == 'close')
                $target = 'closed';
            else
                $target = 'open';
            if (!($st = $this->statusByName($target)))
                return $this->exerr(400, __('Unknown status'));
            if ($st->getState() == 'open' && $ticket->isClosed()) {
                if (!$ticket->isReopenable())
                    return $this->exerr(409, __('Ticket cannot be reopened'));
                if (!$ticket->reopen())
                    return $this->exerr(500, __('Unable to reopen ticket'));
                if ($message)
                    $ticket->logNote(__('Reopened via API'), $message, $name ?: 'API', $alert);
            }
            elseif ($st->getId() != $ticket->getStatusId()) {
                if (!$ticket->setStatus($st, $message, $errors, false, true))
                    return $this->exerr(409, $errors['err'] ?: __('Unable to change status'));
            }
            break;
        default:
            return $this->exerr(400, __('Unknown action'));
        }

        if ($errors && !$entry && in_array(strtolower($action), array('reply', 'note', 'message')))
            return $this->exerr(422, Format::array_implode(': ', '; ', $errors));

        $ticket = Ticket::lookup($ticket->getId());
        $out = array('ok' => true, 'ticket' => $this->ticketSummary($ticket));
        if ($entry)
            $out['entry'] = $this->entrySummary($entry);
        $this->emit($format, 201, $out);
    }

    protected function findTicket($number) {
        $number = trim((string) $number, " #");
        if (preg_match('/^\d+$/', $number) && ($t = Ticket::lookup(array('number' => $number))))
            return $t;
        if (preg_match('/^id:(\d+)$/i', $number, $m))
            return Ticket::lookup((int) $m[1]);
        return null;
    }

    protected function statusByName($name) {
        if (!$name)
            return null;
        if (is_numeric($name))
            return TicketStatus::lookup((int) $name);
        foreach (TicketStatus::objects() as $st) {
            if (!strcasecmp($st->getName(), $name))
                return $st;
        }
        $name = strtolower($name);
        if (in_array($name, array('open', 'closed', 'resolved')))
            return TicketStatus::objects()->filter(array('state' => $name == 'open' ? 'open' : 'closed'))->first();
        return null;
    }

    function ticketSummary(Ticket $ticket, $full=false) {
        global $cfg;
        $owner = $ticket->getOwner();
        $data = array(
            'id' => $ticket->getId(),
            'number' => $ticket->getNumber(),
            'subject' => (string) $ticket->getSubject(),
            'status' => (string) $ticket->getStatus(),
            'state' => $ticket->isOpen() ? 'open' : 'closed',
            'priority' => (string) $ticket->getPriority(),
            'department' => array('id' => (int) $ticket->getDeptId(), 'name' => (string) $ticket->getDeptName()),
            'topic' => ($t = $ticket->getTopic()) ? (string) $t->getFullName() : null,
            'requester' => array('name' => $owner ? (string) $owner->getName() : '',
                'email' => (string) $ticket->getEmail()),
            'assigned' => $ticket->isAssigned() ? array_map('strval', $ticket->getAssignees()) : array(),
            'sla' => ($sla = $ticket->getSLA()) ? (string) $sla->getName() : null,
            'due' => $ticket->getEstDueDate() ? Format::datetime($ticket->getEstDueDate()) : null,
            'overdue' => (bool) $ticket->isOverdue(),
            'answered' => (bool) $ticket->isAnswered(),
            'source' => (string) $ticket->getSource(),
            'created' => (string) $ticket->getCreateDate(),
            'updated' => (string) $ticket->getUpdateDate(),
            'closed' => (string) $ticket->getCloseDate(),
            'url' => sprintf('%s/scp/tickets.php?id=%d', rtrim($cfg->getBaseUrl(), '/'), $ticket->getId()),
        );
        if ($full) {
            require_once INCLUDE_DIR.'class.connector.php';
            $links = array();
            foreach (ConnectorLink::forTicket($ticket->getId()) as $l)
                $links[] = array('connector' => (string) $l->getConnector(), 'type' => $l->getConnector()->getType(),
                    'external_id' => $l->getExternalId(), 'key' => $l->getExternalKey(), 'url' => $l->getExternalUrl());
            $data['links'] = $links;
            $fields = array();
            foreach (DynamicFormEntry::forTicket($ticket->getId()) as $form) {
                foreach ($form->getAnswers() as $a) {
                    $f = $a->getField();
                    if (!$f || in_array($f->get('name'), array('subject', 'message', 'priority')))
                        continue;
                    $fields[$f->get('name') ?: $f->get('label')] = (string) $a->display();
                }
            }
            $data['fields'] = $fields;
        }
        return $data;
    }

    function entrySummary(ThreadEntry $entry) {
        $body = $entry->getBody();
        $text = $body ? $body->getClean() : '';
        if ($entry->format == 'html')
            $text = Format::html2text($text, 100, false);
        $kinds = array('M' => 'message', 'R' => 'response', 'N' => 'note');
        $poster = ($s = $entry->getStaff()) ? (string) $s->getName()
            : (($u = $entry->getUser()) ? (string) $u->getName() : (string) $entry->getPoster());
        return array(
            'id' => $entry->getId(),
            'type' => isset($kinds[$entry->getType()]) ? $kinds[$entry->getType()] : $entry->getType(),
            'poster' => $poster,
            'source' => (string) $entry->getSource(),
            'title' => (string) $entry->getTitle(),
            'text' => trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8')),
            'created' => (string) $entry->created,
        );
    }

    protected function emit($format, $code, array $data) {
        if (strcasecmp($format, 'xml') === 0) {
            require_once INCLUDE_DIR.'api.monitor.php';
            Http::response($code, '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . ApiXmlOutput::encode($data, 'response'), 'application/xml');
        }
        else {
            Http::response($code, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'application/json');
        }
        exit();
    }

    /* private helper functions */

    function createTicket($data, $source = 'API') {

        # Pull off some meta-data
        $alert       = (bool) (isset($data['alert'])       ? $data['alert']       : true);
        $autorespond = (bool) (isset($data['autorespond']) ? $data['autorespond'] : true);

        // Assign default value to source if not defined, or defined as NULL
        $data['source'] ??= $source;

        // Create the ticket with the data (attempt to anyway)
        $errors = array();
        if (($ticket = Ticket::create($data, $errors, $data['source'],
                        $autorespond, $alert)) &&  !$errors)
            return $ticket;

        // Ticket create failed Bigly - got errors?
        $title = null;
        // Got errors?
        if (count($errors)) {
            // Ticket denied? Say so loudly so it can standout from generic
            // validation errors
            if (isset($errors['errno']) && $errors['errno'] == 403) {
                $title = _S('Ticket denied');
                $error = sprintf("%s: %s\n\n%s",
                        $title, $data['email'], $errors['err']);
            } else {
                // unpack the errors
                $error = Format::array_implode("\n", "\n", $errors);
            }
        } else {
            // unknown reason - default
            $error = _S('unknown error');
        }

        $error = sprintf('%s :%s',
                _S('Unable to create new ticket'), $error);
        return $this->exerr($errors['errno'] ?: 500, $error, $title);
    }

    function processEmailRequest() {
        return $this->processEmail();
    }

    function processEmail($data=false, array $defaults = []) {

        try {
            if (!$data)
                $data = $this->getEmailRequest();
            elseif (!is_array($data))
                $data = $this->parseEmail($data);
        } catch (Exception $ex)  {
            throw new EmailParseError($ex->getMessage());
        }

        $data = array_merge($defaults, $data);
        $seen = false;
        if (($entry = ThreadEntry::lookupByEmailHeaders($data, $seen))
            && ($message = $entry->postEmail($data))
        ) {
            if ($message instanceof ThreadEntry) {
                return $message->getThread()->getObject();
            }
            else if ($seen) {
                // Email has been processed previously
                return $entry->getThread()->getObject();
            }
        }

        // Allow continuation of thread without initial message or note
        elseif (($thread = Thread::lookupByEmailHeaders($data))
            && ($message = $thread->postEmail($data))
        ) {
            return $thread->getObject();
        }

        // All emails which do not appear to be part of an existing thread
        // will always create new "Tickets". All other objects will need to
        // be created via the web interface or the API
        try {
            return $this->createTicket($data, 'Email');
        } catch (TicketApiError $err) {
            // Check if the ticket was denied by a filter or banlist
            if ($err->isDenied() && $data['mid']) {
                // We need to log the Message-Id (mid) so we don't
                // process the same email again in subsequent fetches
                $entry = new ThreadEntry();
                $entry->logEmailHeaders(0, $data['mid']);
                // throw TicketDenied exception so the caller can handle it
                // accordingly
                throw new TicketDenied($err->getMessage());
            } else {
                // otherwise rethrow this bad baby as it is!
                throw $err;
            }
        }
    }
}

//Local email piping controller - no API key required!
class PipeApiController extends TicketApiController {

    // Overwrite grandparent's (ApiController) response method.
    function response($code, $resp) {

        // It's important to use postfix exit codes for local piping instead
        // of HTTP's so the piping script can process them accordingly
        switch($code) {
            case 201: //Success
                $exitcode = 0;
                break;
            case 400:
                $exitcode = 66;
                break;
            case 401: /* permission denied */
            case 403:
                $exitcode = 77;
                break;
            case 415:
            case 416:
            case 417:
            case 501:
                $exitcode = 65;
                break;
            case 503:
                $exitcode = 69;
                break;
            case 500: //Server error.
            default: //Temp (unknown) failure - retry
                $exitcode = 75;
        }
        //We're simply exiting - MTA will take care of the rest based on exit code!
        exit($exitcode);
    }

    static function process($sapi=null) {
        $pipe = new PipeApiController($sapi);
        if (($ticket=$pipe->processEmail()))
           return $pipe->response(201,
                   is_object($ticket) ? $ticket->getNumber() : $ticket);

        return $pipe->exerr(416, __('Request failed - retry again!'));
    }

    static function local() {
        return self::process('cli');
    }
}

class TicketApiError extends Exception {

    // Check if exception is because of denial
    public function isDenied() {
        return ($this->getCode() === 403);
    }
}

class TicketDenied extends Exception {}
class EmailParseError extends Exception {}

?>
