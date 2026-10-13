<?php
/*********************************************************************
    connectors.php

    Admin Panel → Manage → Connectors: Linear, Microsoft Teams and
    webhook integrations with per-department channel routing.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/
require('admin.inc.php');
require_once(INCLUDE_DIR.'class.connector.php');
require_once(INCLUDE_DIR.'class.ticket.php');

$connector = $channel = null;
if ($_REQUEST['id'] && !($connector = Connector::lookup((int) $_REQUEST['id'])))
    $errors['err'] = sprintf(__('%s: Unknown or invalid ID.'), __('connector'));
if ($connector && $_REQUEST['cid']
        && (!($channel = ConnectorChannel::lookup((int) $_REQUEST['cid']))
            || $channel->getConnectorId() != $connector->getId())) {
    $errors['err'] = sprintf(__('%s: Unknown or invalid ID.'), __('channel'));
    $channel = null;
}

if ($_POST) {
    switch (strtolower($_POST['do'])) {
    case 'create':
        $_connector = Connector::create(array('type' => $_POST['type']));
        if ($_connector->update($_POST, $errors)) {
            $msg = sprintf(__('Successfully added %s.'), Format::htmlchars($_POST['name']));
            $type = array('type' => 'created');
            Signal::send('object.created', $_connector, $type);
            Http::redirect('connectors.php?id='.$_connector->getId().'#channels');
        } elseif (!$errors['err']) {
            $errors['err'] = sprintf('%s %s',
                sprintf(__('Unable to add %s.'), __('this connector')),
                __('Correct any errors below and try again.'));
        }
        $connector = $_connector;
        $_REQUEST['a'] = 'add';
        $_REQUEST['type'] = $_POST['type'];
        break;
    case 'update':
        if (!$connector)
            $errors['err'] = sprintf(__('%s: Unknown or invalid'), __('connector'));
        elseif ($connector->update($_POST, $errors))
            $msg = sprintf(__('Successfully updated %s.'), __('this connector'));
        elseif (!$errors['err'])
            $errors['err'] = sprintf('%s %s',
                sprintf(__('Unable to update %s.'), __('this connector')),
                __('Correct any errors below and try again.'));
        break;
    case 'test':
        if (!$connector)
            $errors['err'] = sprintf(__('%s: Unknown or invalid'), __('connector'));
        else {
            try {
                $result = $connector->getBackend()->test();
                if ($result['ok']) {
                    $connector->save();
                    $msg = $result['message'];
                    $connector->logEvent('out', 'test', 'ok', $result['message']);
                }
                else
                    $errors['err'] = sprintf('%s: %s', __('Connection test failed'), $result['message']);
            }
            catch (Throwable $t) {
                $errors['err'] = sprintf('%s: %s', __('Connection test failed'), $t->getMessage());
            }
        }
        break;
    case 'add-channel':
        if (!$connector)
            $errors['err'] = sprintf(__('%s: Unknown or invalid'), __('connector'));
        else {
            $_channel = ConnectorChannel::create(array('connector_id' => $connector->getId()));
            if ($_channel->update($_POST, $errors)) {
                $msg = sprintf(__('Successfully added %s.'), __('the channel'));
                Http::redirect('connectors.php?id='.$connector->getId().'#channels');
            } elseif (!$errors['err'])
                $errors['err'] = sprintf('%s %s',
                    sprintf(__('Unable to add %s.'), __('this channel')),
                    __('Correct any errors below and try again.'));
            $channel = $_channel;
            $_REQUEST['a'] = 'add-channel';
        }
        break;
    case 'update-channel':
        if (!$channel)
            $errors['err'] = sprintf(__('%s: Unknown or invalid'), __('channel'));
        elseif ($channel->update($_POST, $errors)) {
            $msg = sprintf(__('Successfully updated %s.'), __('the channel'));
            Http::redirect('connectors.php?id='.$connector->getId().'#channels');
        } elseif (!$errors['err'])
            $errors['err'] = sprintf('%s %s',
                sprintf(__('Unable to update %s.'), __('this channel')),
                __('Correct any errors below and try again.'));
        break;
    case 'delete-channel':
        if (!$channel)
            $errors['err'] = sprintf(__('%s: Unknown or invalid'), __('channel'));
        else {
            $channel->delete();
            $channel = null;
            $msg = sprintf(__('Successfully deleted %s.'), __('the channel'));
            Http::redirect('connectors.php?id='.$connector->getId().'#channels');
        }
        break;
    case 'retry-jobs':
        if ($connector) {
            ConnectorJob::objects()->filter(array(
                'connector_id' => $connector->getId(),
                'status' => ConnectorJob::STATUS_FAILED,
            ))->update(array(
                'status' => ConnectorJob::STATUS_PENDING,
                'attempts' => 0,
                'next_attempt' => SqlFunction::NOW(),
            ));
            $n = ConnectorJob::processPending(50);
            $msg = sprintf(__('Queue processed (%d jobs run).'), $n);
        }
        break;
    case 'clear-events':
        if ($connector) {
            ConnectorEvent::objects()->filter(array('connector_id' => $connector->getId()))->delete();
            $connector->last_error = null;
            $connector->save();
            $msg = __('Activity log cleared.');
        }
        break;
    case 'mass_process':
        if (!$_POST['ids'] || !is_array($_POST['ids']) || !count($_POST['ids'])) {
            $errors['err'] = sprintf(__('You must select at least %s.'), __('one connector'));
        } else {
            $count = count($_POST['ids']);
            $items = Connector::objects()->filter(array('id__in' => array_values($_POST['ids'])));
            switch (strtolower($_POST['a'])) {
            case 'enable':
                foreach ($items as $c) { $c->setFlag(Connector::FLAG_ACTIVE, true); $c->save(); }
                $msg = sprintf(__('Successfully enabled %s'), _N('selected connector', 'selected connectors', $count));
                break;
            case 'disable':
                foreach ($items as $c) { $c->setFlag(Connector::FLAG_ACTIVE, false); $c->save(); }
                $msg = sprintf(__('Successfully disabled %s'), _N('selected connector', 'selected connectors', $count));
                break;
            case 'delete':
                foreach ($items as $c) $c->delete();
                $msg = sprintf(__('Successfully deleted %s'), _N('selected connector', 'selected connectors', $count));
                break;
            default:
                $errors['err'] = __('Unknown action - get technical help.');
            }
        }
        break;
    default:
        $errors['err'] = __('Unknown action');
    }
}

$page = 'connectors.inc.php';
$tip_namespace = 'manage.connectors';
if ($connector && ($channel || $_REQUEST['a'] == 'add-channel'))
    $page = 'connector-channel.inc.php';
elseif ($connector || $_REQUEST['a'] == 'add')
    $page = 'connector.inc.php';

$nav->setTabActive('manage');
$ost->addExtraHeader('<meta name="tip-namespace" content="'.$tip_namespace.'" />',
    "$('#content').data('tipNamespace', '".$tip_namespace."');");
require(STAFFINC_DIR.'header.inc.php');
require(STAFFINC_DIR.$page);
include(STAFFINC_DIR.'footer.inc.php');
?>
