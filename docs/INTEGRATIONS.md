# Integrations: Linear, Microsoft Teams and webhooks

Connectors live under **Admin Panel → Manage → Connectors**. A connector holds
the credentials for one external system; its **channels** map an external
key (a Linear team, a Teams channel, a webhook routing key) to a department.
That mapping is what makes tickets land in the right department and makes
replies and alerts go back to the right place.

```
Linear team ENG  ──channel──▶  Engineering department
Linear team OPS  ──channel──▶  Operations department
Teams #helpdesk  ──channel──▶  Support department (default)
```

Every connector has:

| Flag | Meaning |
|------|---------|
| Inbound | the external system may create tickets and post to them |
| Outbound | ticket activity is pushed to the external system |
| Alerts | SLA and incident alerts are posted to the mapped channels |
| Push notes | internal notes are pushed too (off: only public activity) |
| Sync status | closing / reopening is mirrored both ways |

Every channel has its own inbound / outbound / alerts switches, can be
marked **default** (used for departments that have no channel of their own)
and can be disabled without deleting it.

Deliveries are queued (`connector_job`) and retried with back-off from cron;
every event is written to the connector's log (`connector_event`) which is
visible on the connector page. Objects that exist on both sides are linked
in `connector_link` (ticket ↔ issue, thread entry ↔ comment), which is how
replies are threaded correctly and how a comment created by osTicket is not
echoed back as a new reply (loop prevention).

The webhook endpoint for every connector is

```
<helpdesk url>/api/connectors/<connector id>/<type>     type = linear | teams | webhook
```

The endpoint is shown on the connector page once it has been saved.

## Linear

**Setup**

1. Admin Panel → Manage → Connectors → Add Connector → Linear.
2. In Linear, *Settings → API* → create a personal API key (or an OAuth app
   token) that can read and write the teams you want to connect. Paste it
   into **API key**.
3. *Settings → API → Webhooks* → create a webhook with the connector's URL,
   enable the **Issues** and **Comments** resources and paste the signing
   secret into **Webhook secret**. Requests are verified with the
   `Linear-Signature` header.
4. Save, then **Test connection**: this validates the key and caches the
   team and workflow state list.
5. **Channels** tab → *Add channel* for each team: the channel key is the
   Linear team key (`ENG`), pick the department, tick inbound / outbound /
   alerts. Mark one channel as default if you want unmapped departments to
   post somewhere.

**Options**

| Option | Effect |
|--------|--------|
| Create issues for tickets | every new ticket in a mapped department becomes a Linear issue in that team |
| Comments become | *reply* (customer-visible, posted as the configured agent), *note* (internal) or *auto* |
| Reply as agent | the agent identity used for replies originating in Linear |
| Email replies | send the usual email alerts for replies that come from Linear |
| Reporter email | requester address for tickets created from Linear issues when the issue has no reporter email |
| Labels | labels added to issues created by osTicket |
| Project / help topic | default project for created issues and help topic for created tickets |

**What flows where**

| In Linear | In osTicket |
|-----------|-------------|
| Issue created in a mapped team | ticket in the mapped department, source *Linear*, link to the issue |
| Comment on a linked issue | reply or note on the ticket (per *Comments become*) |
| Issue moved to a completed / cancelled state | ticket closed |
| Issue reopened | ticket reopened |

| In osTicket | In Linear |
|-------------|-----------|
| Ticket created in a mapped department | issue created (if *Create issues* is on) |
| Reply / note posted | comment on the linked issue |
| Ticket closed / reopened | issue state updated (if *Sync status* is on) |
| Assigned / transferred | comment on the issue |
| SLA warning, breach or escalation; incident opened / resolved | comment on the linked issue, or a new issue in the department's channel when the alert has no ticket |

## Microsoft Teams

**Setup**

1. Admin Panel → Manage → Connectors → Add Connector → Microsoft Teams.
2. For outbound posts: in each Teams channel open *Connectors / Workflows →
   Incoming Webhook*, create one and paste the URL into the channel's
   **Incoming webhook URL** (per channel) or into the connector's default
   webhook URL.
3. For inbound commands: in Teams create an **Outgoing Webhook** (team
   settings → Apps → Create an outgoing webhook) named exactly like
   **Bot name**, with the connector's URL as callback. Paste its security
   token into **Outgoing secret**. Requests are verified with the
   `Authorization: HMAC …` header Teams sends.
4. Add a channel per department: the channel key is the Teams channel id
   (shown in the channel link; the connector page explains where to find
   it), the department, and the incoming webhook URL for that channel.

**Commands** (from a channel with the outgoing webhook, after @mentioning
the bot):

```
@Helpdesk new: Printer on floor 2 is jamming     → opens a ticket in the channel's department
@Helpdesk #123 We replaced the roller, try again → posts a reply to ticket 123
@Helpdesk note #123 Spare part ordered           → posts an internal note
@Helpdesk status #123                            → shows status, assignee, due date
@Helpdesk close #123 Fixed on site               → closes the ticket
@Helpdesk help                                   → lists the commands
```

Outbound posts are adaptive cards with the ticket number, subject,
requester, department, priority, a link to the ticket and, for alerts,
the SLA or incident detail.

## Webhook / custom API

The generic connector works with any system that can send and receive JSON
over HTTPS (chat tools, monitoring, CRMs, automation platforms such as n8n,
Zapier or Make).

**Outbound**: choose the events to send and the URL. Each delivery is a
`POST` with

```
Content-Type: application/json
X-OSTicket-Event: ticket.created
X-OSTicket-Delivery: <unique id>
X-OSTicket-Signature: sha256=<HMAC-SHA256 of the body with the signing secret>
```

and a body such as

```json
{
  "event": "ticket.response",
  "timestamp": "2026-10-13T07:34:00+00:00",
  "helpdesk": {"name": "Acme Support", "url": "https://help.acme.example"},
  "channel": {"key": "support-room", "name": "#support", "department": "Support"},
  "ticket": {"id": 9, "number": "421097", "subject": "Laptop will not boot",
             "status": "Open", "priority": "High", "department": "Support",
             "requester": {"name": "Eve Adams", "email": "eve@example.com"},
             "assigned": [], "due": "2026-10-15 14:00:00", "url": "https://…/scp/tickets.php?id=9"},
  "entry": {"id": 10, "type": "R", "kind": "Reply", "poster": "Alex Admin",
            "source": "API", "text": "Thanks Eve, …"}
}
```

Alerts (`"event": "alert"`) carry an `alert` object with `kind` (`sla` or
`incident`), `event` (`warning`, `response`, `overdue`, `escalated`, `open`,
`resolved`…), `severity`, `title`, `facts` and a link. *Test connection*
sends a `ping` event so the receiver can be checked before going live.

**Inbound**: `POST <helpdesk url>/api/connectors/<id>/webhook` with the
header `X-Webhook-Token: <inbound token>` (or `?token=`), optionally also
signed with `X-Webhook-Signature: sha256=<HMAC of the body with the signing
secret>`.

| `event` | Fields | Result |
|---------|--------|--------|
| `ticket.create` (default) | `channel`, `subject`, `message`, `name`, `email`, `external_id`, `external_url`, `priority`, `topic_id` | ticket in the channel's department; the same `external_id` again returns the existing ticket (idempotent) |
| `reply` / `note` / `message` | `ticket` or `number` or `external_id`, `message`, `name`, `email`, `entry_id` | posts to the ticket; `reply` needs an agent identity (*Reply as agent* or a matching `email`), otherwise it is stored as a note |
| `close` / `reopen` | `ticket` / `number` / `external_id`, optional `message` | changes status |
| `ping` | — | returns `{"ok": true}` |

Responses are JSON with `ok` and the ticket (and entry) that was touched;
errors use HTTP 401 (bad token), 404 (unknown ticket or channel) or 422.

## Department routing rules

1. A ticket's department decides the channel: the channel mapped to that
   department, otherwise the connector's default channel, otherwise nothing
   is sent.
2. Inbound requests name the channel (`channel` key, Linear team, Teams
   channel). Unknown keys fall back to the default channel; if there is none
   the request is rejected.
3. The **Channels** tab on a department (Admin Panel → Agents →
   Departments) lists every channel pointing at it across all connectors.

## Operations

* **Cron** must run (`api/cron.php`, `POST /api/tasks/cron` or the
  auto-cron): it retries failed deliveries, runs SLA escalation and
  re-notifies incidents.
* The connector page shows the delivery log, pending jobs and the last
  error; *Run queue / retry failed* pushes the queue immediately.
* Outbound calls use cURL (or PHP streams) with a 10 second timeout, follow
  the server's proxy settings and never block the agent's request for more
  than one attempt; failures are retried later.
