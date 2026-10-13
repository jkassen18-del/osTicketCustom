<?php
/*********************************************************************
    incidents.php

    Monitoring inbox: incidents reported by external systems through
    the monitoring API (POST /api/monitor/events.json).

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
require('staff.inc.php');
require_once(INCLUDE_DIR.'class.ticket.php');
require_once(INCLUDE_DIR.'class.incident.php');

$incident = null;
if ($_REQUEST['id'] && !($incident = Incident::lookup((int) $_REQUEST['id'])))
    $errors['err'] = sprintf(__('%s: Unknown or invalid ID.'), __('incident'));

$canManage = function($i) use ($thisstaff) {
    if ($thisstaff->isAdmin())
        return true;
    $dept = $i ? $i->getDept() : null;
    return !$dept || $thisstaff->canAccessDept($dept);
};

if ($_POST) {
    switch (strtolower($_POST['do'])) {
    case 'acknowledge':
        if (!$incident || !$canManage($incident))
            $errors['err'] = __('Permission denied');
        elseif ($incident->acknowledge($thisstaff, $_POST['note']))
            $msg = __('Incident acknowledged');
        else
            $errors['err'] = __('Unable to acknowledge the incident');
        break;
    case 'resolve':
        if (!$incident || !$canManage($incident))
            $errors['err'] = __('Permission denied');
        elseif ($incident->resolve($thisstaff, $_POST['note'],
                isset($_POST['close_ticket']) ? (bool) $_POST['close_ticket'] : null))
            $msg = __('Incident resolved');
        else
            $errors['err'] = __('Unable to resolve the incident');
        break;
    case 'reopen':
        if (!$incident || !$canManage($incident))
            $errors['err'] = __('Permission denied');
        elseif ($incident->reopen())
            $msg = __('Incident reopened');
        else
            $errors['err'] = __('Unable to reopen the incident');
        break;
    case 'delete':
        if (!$incident || !$thisstaff->isAdmin())
            $errors['err'] = __('Permission denied');
        else {
            $incident->delete();
            $incident = null;
            $msg = __('Incident deleted');
        }
        break;
    case 'mass_process':
        if (!$_POST['ids'] || !is_array($_POST['ids']) || !count($_POST['ids'])) {
            $errors['err'] = sprintf(__('You must select at least %s.'), __('one incident'));
        } else {
            $n = 0;
            foreach (Incident::objects()->filter(array('id__in' => array_values($_POST['ids']))) as $i) {
                if (!$canManage($i))
                    continue;
                switch (strtolower($_POST['a'])) {
                case 'acknowledge':
                    if ($i->acknowledge($thisstaff)) $n++;
                    break;
                case 'resolve':
                    if ($i->resolve($thisstaff)) $n++;
                    break;
                case 'delete':
                    if ($thisstaff->isAdmin() && $i->delete()) $n++;
                    break;
                }
            }
            $msg = sprintf(__('%d incidents updated'), $n);
        }
        break;
    }
}

$page = $incident ? 'incident.inc.php' : 'incidents.inc.php';
$nav->setTabActive('dashboard', 'incidents.php');
$ost->addExtraHeader('<meta name="tip-namespace" content="dashboard.incidents" />',
    "$('#content').data('tipNamespace', 'dashboard.incidents');");
$ost->setPageTitle(__('osTicket :: Incidents'));
require(STAFFINC_DIR.'header.inc.php');
require(STAFFINC_DIR.$page);
include(STAFFINC_DIR.'footer.inc.php');
?>
