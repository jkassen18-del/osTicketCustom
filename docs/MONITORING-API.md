# Monitoring API and incidents

Any system that can make an HTTP request — Grafana, Prometheus Alertmanager,
Uptime Kuma, a cron job, a CI pipeline, an application's error handler — can
report problems to the help desk. Reports become **incidents**, incidents
open **tickets** in the right department and raise **alerts** in that
department's Linear / Teams / webhook channels with full detail.

## Endpoint

```
POST <helpdesk url>/api/monitor/events.json        (or .xml)
X-API-Key: <API key with the "Can report incidents" scope>
Content-Type: application/json
```

Create the key under **Admin Panel → Manage → API**; the IP address of the
reporting system must match the key's address, as for any osTicket API key.

## Payload

One event, or a batch: `{"events": [ {...}, {...} ]}`.

| Field | Required | Notes |
|-------|----------|-------|
| `source` | yes | the reporting system (`payments-api`, `db-01`, `backup-runner`) |
| `title` | yes | short summary |
| `key` (alias `dedupe_key`, `external_id`, `id`) | recommended | identifies *this* problem; repeats with the same `source` + `key` update the existing incident instead of creating a new one |
| `severity` | no | `critical`, `error`, `warning`, `info`; aliases accepted: `high`/`major`/`p2` → error, `fatal`/`p1`/`emergency` → critical, `minor`/`low`/`p3` → warning, `ok`/`debug` → info |
| `status` | no | `firing` (default) opens or re-notifies; `resolved` (`ok`, `recovered`, `closed`, `up`, `clear`) resolves; `acknowledged` (`ack`) marks as acknowledged |
| `detail` (alias `message`) | no | long description, shown on the incident and in the ticket and alerts |
| `url` | no | link back to the dashboard, runbook or log |
| `department` or `dept_id` | no | department name or id; otherwise the incident default department (Settings → Monitoring) or the system default |
| `tags` | no | list of strings |
| `extra` | no | object with any additional facts (host, region, …); they are listed in the ticket |
| `occurred_at` | no | ISO-8601 timestamp of the observation |
| `create_ticket` | no | override the global "create a ticket" setting for this event |
| `name`, `email` | no | requester shown on the ticket (default: the source and the monitoring address) |

Example:

```json
{"source": "payments-api",
 "key": "payments-api/error-rate",
 "title": "Error rate above 5%",
 "severity": "critical",
 "status": "firing",
 "detail": "502 responses from upstream for the last 5 minutes",
 "url": "https://grafana.example.com/d/payments",
 "department": "Engineering",
 "tags": ["prod", "eu-west"],
 "extra": {"region": "eu-west-1", "error_rate": "6.1%"}}
```

Response (`201 Created` for new or updated incidents):

```json
{"ok": true, "received": 1, "failed": 0,
 "results": [{"ok": true, "incident_id": 5, "source": "payments-api",
              "key": "payments-api/error-rate", "severity": "critical",
              "status": "open", "occurrences": 1, "department": "Engineering",
              "first_seen": "2026-10-13 07:34:32", "last_seen": "2026-10-13 07:34:32",
              "ticket": {"id": 12, "number": "302262", "status": "Open",
                         "url": "https://…/scp/tickets.php?id=12"},
              "links": [], "url": "https://…/scp/incidents.php?id=5"}]}
```

A batch returns one result per event; `failed` counts events that were
rejected (missing `source` / `title`) and their `error`.

## What happens

1. **New problem** (`source` + `key` not seen before, or previously
   resolved): an incident is created, a ticket is opened in the mapped
   department (if the severity reaches the configured minimum and ticket
   creation is on), and an *incident open* alert goes to the department's
   connector channels and to the department's email alerts.
2. **Repeat** of an open problem: `occurrences` and `last_seen` are
   updated, the detail is refreshed, and the channels are notified again no
   more often than every *re-notify* minutes (Settings → Monitoring).
3. **Severity increase**: counted as an escalation and notified
   immediately.
4. **Resolved**: the incident is marked resolved, its ticket is closed
   (configurable), and a *resolved* alert is sent.
5. **Acknowledged**: the incident is marked acknowledged (from the API or
   from the incident page); re-notification stops until it fires again.

Tickets opened this way have the source **Monitor**, the system as the
requester name, the configured reporter address and the priority mapped
from the severity (critical → Emergency, error → High, warning → Normal,
info → Low). Agents handle them like any other ticket.

## Incident inbox

**Agent Panel → Dashboard → Incidents** lists active incidents with
severity, system, department, ticket, occurrences and last seen, with
filters by status, severity, system and text. An incident page shows the
detail, the linked ticket and connector links, and offers **Acknowledge**,
**Resolve** and **Delete**. Resolved incidents are kept for the retention
period (Settings → Monitoring) and removed by cron afterwards.

```
GET /api/monitor/incidents.json?status=open|acknowledged|resolved|all&severity=…&source=…&since=…&limit=…
```

returns the same summaries for dashboards and other tools.

## Settings (Admin Panel → Settings → Monitoring)

| Setting | Effect |
|---------|--------|
| Create a ticket | open a ticket for new incidents |
| Close the ticket when resolved | close it on the resolved event |
| Minimum severity for tickets | skip tickets for info / warning noise while still recording the incident and alerting |
| Default department / help topic | used when the event names none |
| Reporter email | requester address on incident tickets (default `monitoring@<helpdesk mail domain>`) |
| Re-notify every N minutes | throttle for repeated firing events |
| Keep resolved incidents for N days | retention |

## Examples

Alertmanager webhook receiver (one event per alert):

```
curl -X POST https://help.acme.example/api/monitor/events.json \
  -H 'X-API-Key: …' -H 'Content-Type: application/json' \
  -d '{"source":"alertmanager","key":"'"$ALERT_FINGERPRINT"'","title":"'"$ALERT_NAME"'",
       "severity":"'"$SEVERITY"'","status":"'"$STATUS"'","detail":"'"$DESCRIPTION"'",
       "url":"'"$GENERATOR_URL"'","department":"Engineering"}'
```

A shell health check:

```
if ! curl -fsS https://shop.example/health >/dev/null; then STATUS=firing; else STATUS=resolved; fi
curl -X POST "$HELPDESK/api/monitor/events.json" -H "X-API-Key: $KEY" -H 'Content-Type: application/json' \
  -d "{\"source\":\"shop-health\",\"key\":\"shop/health\",\"title\":\"Shop health check failing\",\"severity\":\"error\",\"status\":\"$STATUS\"}"
```
