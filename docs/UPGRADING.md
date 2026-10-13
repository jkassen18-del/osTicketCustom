# Upgrading and schema notes

## From stock osTicket 1.18.x

1. Back up the database and the `include/ost-config.php` file.
2. Copy this repository's files over the installation (or switch the
   checkout to this branch). Keep your `ost-config.php`, `attachments`
   directory and any plugins.
3. Open `/scp/upgrade.php` as an administrator. The upgrader applies the
   patch `include/upgrader/streams/core/5fb92bef-37b2e9dc.patch.sql`,
   which:
   * adds `response_period`, `warn_percent` and `escalation_rules` to
     `sla`;
   * adds the `can_read_tickets`, `can_reply_tickets` and
     `can_report_incidents` scopes to `api_key`;
   * extends `ticket.source` with `Linear`, `Teams` and `Monitor`;
   * creates `connector`, `connector_channel`, `connector_link`,
     `connector_event`, `connector_job`, `incident` and `sla_event`;
   * registers the "SLA Plan" queue column;
   * sets the schema signature to `37b2e9dcd457c62446ed0fd2d247906b`.
4. Make sure cron is running; it now also drives SLA escalation, connector
   delivery retries and incident re-notification.

Fresh installs use `setup/inc/streams/core/install-mysql.sql`, which already
contains everything above.

## Configuration keys

All new settings are stored in the `config` table (namespace `core`) and
edited from the admin panel:

| Area | Keys |
|------|------|
| Branding | `brand_accent`, `brand_theme`, `brand_density`, `brand_nav_style`, `brand_name`, `brand_tagline`, `brand_custom_css`, `brand_hide_powered`, `brand_favicon_id` |
| Incidents | `incident_create_ticket`, `incident_close_ticket`, `incident_ticket_min_severity`, `incident_dept_id`, `incident_topic_id`, `incident_reporter_email`, `incident_renotify_minutes`, `incident_retention_days` |

Per-agent dashboard and report preferences are stored in the `config`
table under the `dashboard.staff.<id>` and `reports.staff.<id>`
namespaces.

## Behavioural changes to be aware of

* `osTicket::is_cli()` no longer treats PHP's built-in web server
  (`php -S`) as a command-line run, so the REST API works under it for
  development.
* `Format::elapsedTime()` no longer requires the bcmath extension.
* Values written with `SqlFunction::NOW()` and formatted before the model is
  refetched are rendered as the current time instead of raising a type
  error.
* API requests may contain lists of scalar values (for example `tags`)
  where the request structure declares `array('*' => '*')`.
* `Ticket::markOverdue()` emits a `ticket.overdue` signal.

## Files added

```
api/http.php                          new routes
include/api.connectors.php            inbound webhooks
include/api.monitor.php               incident reporting API
include/api.tickets.php               search / get / act endpoints
include/class.connector.php           connector models, queue, manager
include/class.httpclient.php          outbound HTTP
include/class.incident.php            incidents
include/class.sla_escalation.php      SLA escalator and events
include/class.dashboard.php           dashboard metrics and report builder
include/connectors/{linear,teams,webhook}.php
include/staff/{connectors,connector,connector-channel,incidents,incident,access,reports,dashboard}.inc.php
include/staff/settings-{branding,incidents}.inc.php
include/staff/templates/sla-adjust.tmpl.php
scp/{connectors,incidents,access,reports}.php
scp/css/modern.css  scp/js/modern.js  scp/js/charts.js
```
