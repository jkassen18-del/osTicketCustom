<?php
/*********************************************************************
    api.connectors.php

    Inbound webhook endpoint for connectors:

        POST /api/connectors/<id>/<type>

    Authentication is delegated to the connector driver (HMAC signature
    for Linear and Teams, shared token / signature for generic webhooks).

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

include_once INCLUDE_DIR.'class.api.php';
include_once INCLUDE_DIR.'class.ticket.php';
include_once INCLUDE_DIR.'class.connector.php';

class ConnectorApiController extends ApiController {

    function inbound($id, $type) {
        global $ost;

        if ($ost && $ost->isUpgradePending())
            return $this->exerr(503, __('System upgrade is pending'));

        if (!($connector = Connector::lookup((int) $id))
                || strcasecmp($connector->getType(), $type))
            return $this->exerr(404, __('Unknown connector'));

        if (!$connector->isActive() || !$connector->acceptsInbound())
            return $this->exerr(403, __('Connector is disabled or does not accept inbound requests'));

        $request = ConnectorRequest::fromGlobals();
        try {
            $response = $connector->getBackend()->handleInbound($request);
        }
        catch (Throwable $t) {
            $connector->logEvent('in', 'request', 'error', $t->getMessage());
            return $this->exerr(500, __('Internal error while processing the request'));
        }
        $this->respond($response);
    }

    /**
     * Driver responses: array('status' => int, 'json' => array)
     * or array('status' => int, 'text' => string)
     */
    protected function respond($response) {
        $status = isset($response['status']) ? (int) $response['status'] : 200;
        if (isset($response['json'])) {
            Http::response($status,
                json_encode($response['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'application/json');
        }
        else {
            Http::response($status, isset($response['text']) ? $response['text'] : '', 'text/plain');
        }
        exit();
    }
}
?>
