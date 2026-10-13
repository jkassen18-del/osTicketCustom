<?php
/*********************************************************************
    http.php

    HTTP controller for the osTicket API

    Jared Hancock
    Copyright (c)  2006-2013 osTicket
    http://www.osticket.com

    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.

    vim: expandtab sw=4 ts=4 sts=4:
**********************************************************************/
require 'api.inc.php';
# Include the main api urls
require_once INCLUDE_DIR."class.dispatcher.php";
$dispatcher = patterns('',
        url_post("^/tickets\.(?P<format>xml|json|email)$", array('api.tickets.php:TicketApiController','create')),
        // Read / reply API (osTicketCustom)
        url_get("^/tickets\.(?P<format>xml|json)$", array('api.tickets.php:TicketApiController','search')),
        url_get("^/tickets/(?P<number>[\w-]+)\.(?P<format>xml|json)$", array('api.tickets.php:TicketApiController','get')),
        url_post("^/tickets/(?P<number>[\w-]+)/(?P<action>reply|note|message|close|reopen|status)\.(?P<format>xml|json)$", array('api.tickets.php:TicketApiController','act')),
        // Connector webhooks (Linear, Microsoft Teams, generic)
        url_post("^/connectors/(?P<id>\d+)/(?P<type>[a-z]+)/?$", array('api.connectors.php:ConnectorApiController','inbound')),
        // System monitoring / incident reporting
        url_post("^/monitor/events\.(?P<format>xml|json)$", array('api.monitor.php:MonitorApiController','report')),
        url_get("^/monitor/incidents\.(?P<format>xml|json)$", array('api.monitor.php:MonitorApiController','search')),
        url('^/tasks/', patterns('',
                url_post("^cron$", array('api.cron.php:CronApiController', 'execute'))
         ))
        );

// Send api signal so backend can register endpoints
Signal::send('api', $dispatcher);
# Call the respective function
print $dispatcher->resolve(Osticket::get_path_info());
?>
