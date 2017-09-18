<?php

namespace Castor;

if (!function_exists('http_build_url')) {
    if (!defined('HTTP_URL_REPLACE')) {
        define('HTTP_URL_REPLACE', 1);
    }
    if (!defined('HTTP_URL_JOIN_PATH')) {
        define('HTTP_URL_JOIN_PATH', 2);
    }
    if (!defined('HTTP_URL_JOIN_QUERY')) {
        define('HTTP_URL_JOIN_QUERY', 4);
    }
    if (!defined('HTTP_URL_STRIP_USER')) {
        define('HTTP_URL_STRIP_USER', 8);
    }
    if (!defined('HTTP_URL_STRIP_PASS')) {
        define('HTTP_URL_STRIP_PASS', 16);
    }
    if (!defined('HTTP_URL_STRIP_AUTH')) {
        define('HTTP_URL_STRIP_AUTH', 32);
    }
    if (!defined('HTTP_URL_STRIP_PORT')) {
        define('HTTP_URL_STRIP_PORT', 64);
    }
    if (!defined('HTTP_URL_STRIP_PATH')) {
        define('HTTP_URL_STRIP_PATH', 128);
    }
    if (!defined('HTTP_URL_STRIP_QUERY')) {
        define('HTTP_URL_STRIP_QUERY', 256);
    }
    if (!defined('HTTP_URL_STRIP_FRAGMENT')) {
        define('HTTP_URL_STRIP_FRAGMENT', 512);
    }
    if (!defined('HTTP_URL_STRIP_ALL')) {
        define('HTTP_URL_STRIP_ALL', 1024);
    }

    function http_build_url($url, $parts = array(), $flags = HTTP_URL_REPLACE, &$new_url = array())
    {
        is_array($url) || $url = parse_url($url);
        is_array($parts) || $parts = parse_url($parts);

        isset($url['query']) && is_string($url['query']) || $url['query'] = null;
        isset($parts['query']) && is_string($parts['query']) || $parts['query'] = null;

        $keys = array('user', 'pass', 'port', 'path', 'query', 'fragment');

        // HTTP_URL_STRIP_ALL and HTTP_URL_STRIP_AUTH cover several other flags.
        if ($flags & HTTP_URL_STRIP_ALL) {
            $flags |= HTTP_URL_STRIP_USER | HTTP_URL_STRIP_PASS | HTTP_URL_STRIP_PORT | HTTP_URL_STRIP_PATH | HTTP_URL_STRIP_QUERY | HTTP_URL_STRIP_FRAGMENT;
        } elseif ($flags & HTTP_URL_STRIP_AUTH) {
            $flags |= HTTP_URL_STRIP_USER | HTTP_URL_STRIP_PASS;
        }

        // Schema and host are alwasy replaced
        foreach (array('scheme', 'host') as $part) {
            if (isset($parts[$part])) {
                $url[$part] = $parts[$part];
            }
        }

        if ($flags & HTTP_URL_REPLACE) {
            foreach ($keys as $key) {
                if (isset($parts[$key])) {
                    $url[$key] = $parts[$key];
                }
            }
        } else {
            if (isset($parts['path']) && ($flags & HTTP_URL_JOIN_PATH)) {
                if (isset($url['path']) && substr($parts['path'], 0, 1) !== '/') {
                    $url['path'] = rtrim(
                                    str_replace(basename($url['path']), '', $url['path']), '/'
                            ) . '/' . ltrim($parts['path'], '/');
                } else {
                    $url['path'] = $parts['path'];
                }
            }

            if (isset($parts['query']) && ($flags & HTTP_URL_JOIN_QUERY)) {
                if (isset($url['query'])) {
                    parse_str($url['query'], $url_query);
                    parse_str($parts['query'], $parts_query);

                    $url['query'] = http_build_query(
                            array_replace_recursive(
                                    $url_query, $parts_query
                            )
                    );
                } else {
                    $url['query'] = $parts['query'];
                }
            }
        }

        if (isset($url['path']) && substr($url['path'], 0, 1) !== '/') {
            $url['path'] = '/' . $url['path'];
        }

        foreach ($keys as $key) {
            $strip = 'HTTP_URL_STRIP_' . strtoupper($key);
            if ($flags & constant($strip)) {
                unset($url[$key]);
            }
        }

        $parsed_string = '';

        if (isset($url['scheme'])) {
            $parsed_string .= $url['scheme'] . '://';
        }

        if (isset($url['user'])) {
            $parsed_string .= $url['user'];

            if (isset($url['pass'])) {
                $parsed_string .= ':' . $url['pass'];
            }

            $parsed_string .= '@';
        }

        if (isset($url['host'])) {
            $parsed_string .= $url['host'];
        }

        if (isset($url['port'])) {
            $parsed_string .= ':' . $url['port'];
        }

        if (!empty($url['path'])) {
            $parsed_string .= $url['path'];
        } else {
            $parsed_string .= '/';
        }

        if (isset($url['query'])) {
            $parsed_string .= '?' . $url['query'];
        }

        if (isset($url['fragment'])) {
            $parsed_string .= '#' . $url['fragment'];
        }

        $new_url = $url;

        return $parsed_string;
    }

}
if (!function_exists('http_parse_headers')) {

    function http_parse_headers($header)
    {
        $retVal = array();
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
        foreach ($fields as $field) {
            if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                $match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function($matches) {
                    return strtoupper($matches[0]);
                }, strtolower(trim($match[1])));
                if (isset($retVal[$match[1]])) {
                    $retVal[$match[1]] = array($retVal[$match[1]], $match[2]);
                } else {
                    $retVal[$match[1]] = trim($match[2]);
                }
            }
        }
        return $retVal;
    }

}

/**
 * Implementação do protocolo SCSP
 *
 * @author Leonardo Sales
 */
class SCSP {

    private $http = NULL;
    private $libHttp = FALSE;
    private $libHttp2 = FALSE;
    private $libCurl = FALSE;
    private $url;
    private $headers;
    private $memory_limit;

    public function __construct($url = NULL, $headers = array())
    {
        $this->memory_limit = ini_get("memory_limit");
        ini_set("memory_limit", -1);
        $this->url = $url;
        $this->headers = $headers;
        if (extension_loaded("http")) {
            $ext = new \ReflectionExtension('http');
            if (version_compare($ext->getVersion(), "2.0.0", ">=")) {
                $this->http = new \http\Client();
                $this->libHttp2 = TRUE;
            } else {
                $this->http = new \HttpRequest($url);
                $this->http->setHeaders($headers);
                if (!empty($url)) {
                    $this->http->setUrl($url);
                }
                $this->libHttp = TRUE;
            }
        } else if (extension_loaded("curl")) {
            $this->curlInit();
            $this->libCurl = TRUE;
        }
//        var_dump($this->libHttp, $this->libHttp2, $this->libCurl);
    }

    public function __destruct()
    {
        if ($this->libCurl) {
            curl_close($this->http);
        }
        ini_set("memory_limit", $this->memory_limit);
    }

    private function curlInit()
    {
        $this->http = curl_init($this->url);
        $mergedHeaders = array();
        if (!empty($this->headers)) {
            foreach ($this->headers as $v => $c) {
                $mergedHeaders[] = $v . ": " . $c;
            }
            $this->headers = $mergedHeaders;
        }
        curl_setopt($this->http, CURLOPT_HTTPHEADER, $this->headers);
    }

    public function write($content, $headers = NULL)
    {
        $url = $this->getUrl();
        if (empty($url)) {
            throw new \Exception("Deve ser especificada uma URL.");
        }
        if ($this->libHttp) {
            if (!empty($headers)) {
                $this->http->addHeaders($headers);
            }
            $this->http->setMethod(HTTP_METH_POST);
            $this->http->setBody($content);
            $http_response = $this->http->send();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getResponseCode();
            $resposta['body'] = $http_response->getBody();
        } else if ($this->libHttp2) {
            $request = new \http\Client\Request('POST', $this->url, $headers);
            $request->getBody()->append($content);
            $this->http->enqueue($request)->send();
            $http_response = $this->http->getResponse();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getTransferInfo()->response_code;
            $resposta['body'] = $http_response->getBody()->toString();
        } else if ($this->libCurl) {
            $this->curlInit();
            $mergedHeaders = array();
            if (!empty($headers)) {
                foreach ($headers as $v => $c) {
                    $mergedHeaders[] = $v . ": " . $c;
                }
                curl_setopt($this->http, CURLOPT_HTTPHEADER, array_merge($this->headers, $mergedHeaders));
            }
            curl_setopt($this->http, CURLOPT_POST, TRUE);
            curl_setopt($this->http, CURLOPT_POSTFIELDS, $content);
            curl_setopt($this->http, CURLOPT_FOLLOWLOCATION, TRUE);
            curl_setopt($this->http, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($this->http, CURLOPT_VERBOSE, TRUE);
            curl_setopt($this->http, CURLOPT_HEADER, TRUE);

            $http_response = curl_exec($this->http);
            $resposta['headers'] = http_parse_headers($http_response);
            $resposta['info']['http_code'] = curl_getinfo($this->http, CURLINFO_HTTP_CODE);
            list(, $body) = explode("\r\n\r\n", $http_response, 2);
            $resposta['body'] = $body;
        }
        return $resposta;
    }

    public function read()
    {
        $url = $this->getUrl();
        if (empty($url)) {
            throw new \Exception("Deve ser especificada uma URL.");
        }
        if ($this->libHttp) {
            $this->http->setMethod(HTTP_METH_GET);
            $http_response = $this->http->send();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getResponseCode();
            $resposta['body'] = $http_response->getBody();
        } else if ($this->libHttp2) {
            $request = new \http\Client\Request('GET', $this->url);
            $this->http->enqueue($request)->send();
            $http_response = $this->http->getResponse();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getTransferInfo()->response_code;
            $resposta['body'] = $http_response->getBody()->toString();
        } else if ($this->libCurl) {
            $this->curlInit();
            curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->http, CURLOPT_VERBOSE, true);
            curl_setopt($this->http, CURLOPT_HEADER, true);
            $http_response = curl_exec($this->http);
            $resposta['headers'] = http_parse_headers($http_response);
            $resposta['info']['http_code'] = curl_getinfo($this->http, CURLINFO_HTTP_CODE);
            list(, $body) = explode("\r\n\r\n", $http_response, 2);
            $resposta['body'] = $body;
        }
        return $resposta;
    }

    public function delete()
    {
        $url = $this->getUrl();
        if (empty($url)) {
            throw new \Exception("Deve ser especificada uma URL.");
        }
        if ($this->libHttp) {
            $this->http->setMethod(HTTP_METH_DELETE);
            $http_response = $this->http->send();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getResponseCode();
            $resposta['body'] = $http_response->getBody();
        } else if ($this->libHttp2) {
            $request = new \http\Client\Request('DELETE', $this->url);
            $this->http->enqueue($request)->send();
            $http_response = $this->http->getResponse();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getTransferInfo()->response_code;
            $resposta['body'] = $http_response->getBody()->toString();
        } else if ($this->libCurl) {
            $this->curlInit();
            curl_setopt($this->http, CURLOPT_HEADER, true);
            curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->http, CURLOPT_VERBOSE, true);
            curl_setopt($this->http, CURLOPT_CUSTOMREQUEST, "DELETE");
            $http_response = curl_exec($this->http);
            $resposta['headers'] = http_parse_headers($http_response);
            $resposta['info']['http_code'] = curl_getinfo($this->http, CURLINFO_HTTP_CODE);
            list(, $body) = explode("\r\n\r\n", $http_response, 2);
            $resposta['body'] = $body;
        }
        return $resposta;
    }

    public function update($content, $headers = NULL)
    {
        $url = $this->getUrl();
        if (empty($url)) {
            throw new \Exception("Deve ser especificada uma URL.");
        }
        if ($this->libHttp) {
            if (!empty($headers)) {
                $this->http->addHeaders($headers);
            }
            $this->http->setMethod(HTTP_METH_PUT);
            $this->http->setPutData($content);
            $http_response = $this->http->send();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getResponseCode();
            $resposta['body'] = $http_response->getBody();
        } else if ($this->libHttp2) {
            $request = new \http\Client\Request('PUT', $this->url, $headers);
            $request->getBody()->append($content);
            $this->http->enqueue($request)->send();
            $http_response = $this->http->getResponse();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getTransferInfo()->response_code;
            $resposta['body'] = $http_response->getBody()->toString();
        } else if ($this->libCurl) {
            $this->curlInit();
            curl_setopt($this->http, CURLOPT_HEADER, true);
            curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->http, CURLOPT_VERBOSE, true);
            curl_setopt($this->http, CURLOPT_PUT, true);
            $http_response = curl_exec($this->http);
            $resposta['headers'] = http_parse_headers($http_response);
            $resposta['info']['http_code'] = curl_getinfo($this->http, CURLINFO_HTTP_CODE);
            list(, $body) = explode("\r\n\r\n", $http_response, 2);
            $resposta['body'] = $body;
        }
        return $resposta;
    }

    public function info()
    {
        $url = $this->getUrl();
        if (empty($url)) {
            throw new \Exception("Deve ser especificada uma URL.");
        }
        if ($this->libHttp) {
            $this->http->setMethod(HTTP_METH_HEAD);
            $http_response = $this->http->send();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getResponseCode();
            $resposta['info']['content_type'] = $http_response->getHeader('Content-Type');
            $resposta['body'] = $http_response->getBody();
        } else if ($this->libHttp2) {
            $request = new \http\Client\Request('HEAD', $this->url);
            $this->http->enqueue($request)->send();
            $http_response = $this->http->getResponse();
            $resposta['headers'] = $http_response->getHeaders();
            $resposta['info']['http_code'] = $http_response->getTransferInfo()->response_code;
            $resposta['info']['content_type'] = $http_response->getTransferInfo()->content_type;
            $resposta['body'] = $http_response->getBody()->toString();
        } else if ($this->libCurl) {
            $this->curlInit();
            curl_setopt($this->http, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($this->http, CURLOPT_HEADER, true);
            curl_setopt($this->http, CURLOPT_CUSTOMREQUEST, 'HEAD');
            curl_setopt($this->http, CURLOPT_NOBODY, true);
            curl_setopt($this->http, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            $http_response = curl_exec($this->http);
            $resposta['headers'] = http_parse_headers($http_response);
            $resposta['info']['http_code'] = curl_getinfo($this->http, CURLINFO_HTTP_CODE);
            $resposta['info']['content_type'] = curl_getinfo($this->http, CURLINFO_CONTENT_TYPE);
            list(, $body) = explode("\r\n\r\n", $http_response, 2);
            $resposta['body'] = $body;
        }
        return $resposta;
    }

    public function setUrl($url)
    {
        $this->url = $url;
        if ($this->libHttp) {
            $this->http->setUrl($this->url);
        } else if ($this->libCurl) {
            curl_setopt($this->http, CURLOPT_URL, $this->url);
        }
    }

    public function getUrl()
    {
        return $this->url;
    }

    private function parseHeaders($headers)
    {
        return $parsed = array_map(function($x) {
            return array_map("trim", explode(":", $x, 2));
        }, array_filter(array_map("trim", explode("\n", $headers))));
    }

}
