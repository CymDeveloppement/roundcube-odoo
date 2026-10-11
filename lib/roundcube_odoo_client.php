<?php

/**
 * Client of the Odoo external API
 *
 * Calls model methods with the JSON-RPC API (/jsonrpc, Odoo 18 and 19) or
 * the JSON-2 API (/json/2, Odoo 19 and later), authenticated with an API key.
 * Read results are kept in a Roundcube cache shared by all users.
 *
 * @license   MIT License: <http://opensource.org/licenses/MIT>
 * @author    Yann Challet (CymDeveloppement)
 */
class roundcube_odoo_client
{
    /** @var string */
    private $url;

    /** @var string */
    private $db;

    /** @var string */
    private $login;

    /** @var string */
    private $key;

    /** @var string 'auto', 'jsonrpc' or 'json2' */
    private $api;

    /** @var \GuzzleHttp\Client */
    private $http;

    /** @var ?rcube_cache */
    private $cache;

    /** @var int JSON-RPC request counter */
    private $request_id = 0;

    /**
     * @param array        $config Keys url, db, login, api_key, api
     * @param \GuzzleHttp\Client $http HTTP client
     * @param ?rcube_cache $cache  Cache of the read results
     */
    public function __construct(array $config, $http, $cache = null)
    {
        $this->url = rtrim($config['url'], '/');
        $this->db = $config['db'];
        $this->login = $config['login'];
        $this->key = $config['api_key'];
        $this->api = in_array($config['api'], array('jsonrpc', 'json2')) ? $config['api'] : 'auto';
        $this->http = $http;
        $this->cache = $cache;
    }

    /**
     * Calls a method of a model with keyword arguments, e.g.
     * call('res.partner', 'search_read', ['domain' => [...], 'fields' => [...]])
     *
     * @return mixed Result of the method
     * @throws Exception on error
     */
    public function call($model, $method, array $kwargs = array())
    {
        if ($this->protocol() == 'json2') {
            return $this->post_json2($model, $method, $kwargs);
        }

        return $this->post_jsonrpc('object', 'execute_kw',
            array($this->db, $this->uid(), $this->key, $model, $method, array(), (object) $kwargs));
    }

    /**
     * Same as call(), with the result kept in the cache
     */
    public function cached_call($model, $method, array $kwargs = array())
    {
        $cache_key = 'call.' . md5(json_encode(array($this->url, $this->db, $this->login, $model, $method, $kwargs)));

        if ($this->cache && ($result = $this->cache->get($cache_key)) !== null) {
            return $result;
        }

        $result = $this->call($model, $method, $kwargs);

        if ($this->cache) {
            $this->cache->set($cache_key, $result);
        }

        return $result;
    }

    /**
     * Major version of the server, e.g. 19
     */
    public function version()
    {
        return $this->server_info()['version'];
    }

    /**
     * Protocol used for the calls: 'jsonrpc' or 'json2'
     */
    public function protocol()
    {
        if ($this->api != 'auto') {
            return $this->api;
        }

        return $this->server_info()['json2'] ? 'json2' : 'jsonrpc';
    }

    /**
     * Version of the server and availability of the JSON-2 API, read from
     * /json/version (Odoo 19 and later), else from the JSON-RPC API
     */
    private function server_info()
    {
        return $this->remember('version', function () {
            try {
                $response = $this->http->request('GET', $this->url . '/json/version', array('http_errors' => false));
            } catch (\GuzzleHttp\Exception\TransferException $e) {
                throw new Exception('Odoo: ' . $e->getMessage());
            }

            $info = json_decode((string) $response->getBody(), true);
            if ($response->getStatusCode() == 200 && !empty($info['version_info'])) {
                return array('version' => (int) $info['version_info'][0], 'json2' => true);
            }

            $info = $this->post_jsonrpc('common', 'version', array());

            return array('version' => (int) $info['server_version_info'][0], 'json2' => false);
        });
    }

    /**
     * Id of the user of the API key, needed by the JSON-RPC API
     */
    private function uid()
    {
        return $this->remember('uid', function () {
            $uid = $this->post_jsonrpc('common', 'authenticate', array($this->db, $this->login, $this->key, (object) array()));
            if (!$uid) {
                throw new Exception("Odoo: authentication failed for {$this->login} on the database {$this->db}");
            }

            return $uid;
        });
    }

    /**
     * Returns a value of the cache, computed by $compute when missing
     */
    private function remember($name, callable $compute)
    {
        $cache_key = $name . '.' . md5($this->url . "\n" . $this->db . "\n" . $this->login . "\n" . $this->key);

        if ($this->cache && ($value = $this->cache->get($cache_key)) !== null) {
            return $value;
        }

        $value = $compute();

        if ($this->cache) {
            $this->cache->set($cache_key, $value);
        }

        return $value;
    }

    private function post_jsonrpc($service, $method, array $args)
    {
        $body = $this->post($this->url . '/jsonrpc', array(), array(
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => array('service' => $service, 'method' => $method, 'args' => $args),
            'id' => ++$this->request_id,
        ));

        if (!empty($body['error'])) {
            $error = $body['error'];
            $message = $error['data']['message'] ?? $error['message'] ?? 'unknown error';
            throw new Exception("Odoo: $service.$method: $message");
        }

        if (!is_array($body) || !array_key_exists('result', $body)) {
            throw new Exception("Odoo: $service.$method: invalid response");
        }

        return $body['result'];
    }

    private function post_json2($model, $method, array $kwargs)
    {
        return $this->post($this->url . '/json/2/' . rawurlencode($model) . '/' . rawurlencode($method), array(
            'Authorization' => 'bearer ' . $this->key,
            'X-Odoo-Database' => $this->db,
        ), (object) $kwargs, "$model.$method");
    }

    private function post($url, array $headers, $payload, $context = null)
    {
        try {
            $response = $this->http->request('POST', $url, array(
                'headers' => $headers + array('Content-Type' => 'application/json; charset=utf-8'),
                'body' => json_encode($payload),
                'http_errors' => false,
            ));
        } catch (\GuzzleHttp\Exception\TransferException $e) {
            throw new Exception('Odoo: ' . $e->getMessage());
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        if ($status != 200) {
            $message = is_array($body) && !empty($body['message']) ? $body['message'] : $response->getReasonPhrase();
            throw new Exception('Odoo: ' . ($context ? "$context: " : '') . "HTTP $status $message");
        }

        return $body;
    }
}
