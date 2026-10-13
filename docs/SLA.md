# SLA targets, escalation and adjustments

SLA plans are managed under **Admin Panel → Manage → SLA**. Each plan has
two tabs.

## Targets

| Field | Meaning |
|-------|---------|
| Grace period (hours) | the resolution window; the ticket is overdue when it passes (stock osTicket) |
| First response target (hours) | how quickly the first agent reply is expected; a *response* alert is raised when it is missed |
| Warning threshold (%) | when this share of the window has elapsed a *warning* alert is raised ("at risk") |
| Pause while answered | tickets waiting on the customer are not marked overdue |
| Transient / disable overdue alerts | as in stock osTicket |
| Schedule | business hours used to compute the due date (stock osTicket) |

## Escalation steps

Steps are run by cron for every open ticket that has passed its due date.
Each step has **After (hours past the due date)** and any combination of:

* raise the priority,
* assign to an agent, a team or the department manager,
* transfer to another department,
* notify: assignee, department manager, all department members, connector
  channels (Linear / Teams / webhook alerts for the department),
* add an internal note (the note text is also used as the alert body).

Steps run once each, in order (`0` fires as soon as the ticket is marked
overdue, `4` four hours later, and so on). Every step, warning, response
alert and breach is recorded in the ticket's SLA history and the ticket
gets an internal note from the "SLA" poster.

## Per-ticket adjustments

On a ticket, **More → Adjust SLA** opens a dialog with

| Mode | Effect |
|------|--------|
| Extend | add N hours to the current due date |
| Set | pick a new due date and time |
| Hold | pause the clock (the ticket is no longer treated as overdue until released) |
| Reset | recompute the due date from the SLA plan as if the ticket was just opened |

A reason is required; adjustments appear in the SLA history and clear the
overdue flag when the new target is in the future. The ticket's side card
shows the plan, the due date, the elapsed share of the window and the
history link.

## Dashboard and reports

* The dashboard's **SLA met** number is the share of tickets created in the
  timeframe that were neither marked overdue nor closed after their due
  date; "at risk" counts warnings raised in the last 7 days.
* The report builder's **SLA compliance (%)** metric gives the same number
  per day / week / month, split by department, agent, topic, priority,
  source, status or SLA plan.

## Alerts

SLA alerts (warning, response target missed, overdue, escalated) are sent
to the people selected in the step, by email, and to the department's
connector channels when the channel has **Alerts** enabled. The alert text
includes the ticket number, subject, requester, department, due date,
assignee and a link.
