<?php
/*********************************************************************
    class.httpclient.php

    Minimal outbound HTTP client used by the connector framework
    (Linear, Microsoft Teams, generic webhooks) and the monitoring
    subsystem. Uses cURL when available and falls back to PHP streams.

    osTicketCustom
    Released under the GNU General Public License WITHOUT ANY WARRANTY.
    See LICENSE.TXT for details.
**********************************************************************/

class HttpClient {
    const DEFAULT_TIMEOUT = 8;

    static $last = null;

    /**
     * Perform an HTTP request.
     *
     * $options:
     *   headers  - (array) name => value
     *   json     - (mixed) body encoded as JSON (sets Content-Type)
     *   body     - (string|array) raw body or form fields
     *   timeout  - (int) seconds
     *
     * Returns an array with keys: status, body, headers, json, error, url
     */
    static function request($method, $url, $options=array()) {
        $method = strtoupper($method ?: 'GET');
        $headers = isset($options['headers']) && is_array($options['headers'])
            ? $options['headers'] : array();
        $timeout = (int) (isset($options['timeout']) ? $options['timeout'] : self::DEFAULT_TIMEOUT);
        $timeout = max(1, min($timeout, 60));
        $body = isset($options['body']) ? $options['body'] : null;

        if (array_key_exists('json', $options)) {
            $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers['Content-Type'] = 'application/json; charset=utf-8';
        }
        elseif (is_array($body)) {
            $body = http_build_query($body);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        if (!isset($headers['User-Agent']))
            $headers['User-Agent'] = 'osTicket-Connectors/'
                . (defined('THIS_VERSION') ? THIS_VERSION : '1.0');
        if (!isset($headers['Accept']))
            $headers['Accept'] = 'application/json, */*;q=0.5';

        $result = array(
            'status' => 0,
            'body' => '',
            'headers' => array(),
            'json' => null,
            'error' => null,
            'url' => $url,
        );

        if (!preg_match('`^https?://[^\s/$.?#].[^\s]*$`i', (string) $url)) {
            $result['error'] = 'Invalid URL';
            return self::finish($result);
        }

        $hdrs = array();
        foreach ($headers as $k => $v)
            $hdrs[] = sprintf('%s: %s', $k, $v);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $hdrs,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min($timeout, 5),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ));
            if ($body !== null && $method !== 'GET' && $method !== 'HEAD')
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

            $raw = curl_exec($ch);
            if ($raw === false) {
                $result['error'] = curl_error($ch) ?: 'Connection failed';
                curl_close($ch);
                return self::finish($result);
            }
            $result['status'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            $result['headers'] = self::parseHeaders(substr($raw, 0, $hsize));
            $result['body'] = (string) substr($raw, $hsize);
        }
        else {
            $ctx = stream_context_create(array('http' => array(
                'method' => $method,
                'header' => implode("\r\n", $hdrs),
                'content' => $body === null ? '' : $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            )));
            $raw = @file_get_contents($url, false, $ctx);
            if ($raw === false) {
                $result['error'] = 'Connection failed';
                return self::finish($result);
            }
            $result['body'] = $raw;
            $rh = isset($http_response_header) ? $http_response_header : array();
            $result['headers'] = self::parseHeaders(implode("\r\n", $rh));
            if ($rh && preg_match('`HTTP/\S+\s+(\d{3})`', $rh[0], $m))
                $result['status'] = (int) $m[1];
        }
        return self::finish($result);
    }

    static function get($url, $headers=array(), $timeout=null) {
        return self::request('GET', $url, array(
            'headers' => $headers, 'timeout' => $timeout ?: self::DEFAULT_TIMEOUT));
    }

    static function postJson($url, $data, $headers=array(), $timeout=null) {
        return self::request('POST', $url, array(
            'headers' => $headers, 'json' => $data,
            'timeout' => $timeout ?: self::DEFAULT_TIMEOUT));
    }

    static function ok($result) {
        return is_array($result) && empty($result['error']);
    }

    /**
     * Compact description of a failed response for logs
     */
    static function describe($result) {
        if (!is_array($result))
            return 'No response';
        $msg = $result['error'] ?: sprintf('HTTP %d', $result['status']);
        if ($result['body']) {
            $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($result['body'])));
            if ($snippet)
                $msg .= ': ' . mb_substr($snippet, 0, 300);
        }
        return $msg;
    }

    private static function finish($result) {
        if ($result['body'] !== '' && $result['body'] !== null) {
            $json = json_decode($result['body'], true);
            if (json_last_error() === JSON_ERROR_NONE)
                $result['json'] = $json;
        }
        if (!$result['error'] && ($result['status'] < 200 || $result['status'] >= 300))
            $result['error'] = sprintf('HTTP %d', $result['status']);
        self::$last = $result;
        return $result;
    }

    private static function parseHeaders($raw) {
        $headers = array();
        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            if (strpos($line, ':') === false)
                continue;
            list($k, $v) = explode(':', $line, 2);
            $headers[strtolower(trim($k))] = trim($v);
        }
        return $headers;
    }
}
?>
