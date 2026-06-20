<?php

namespace App\lib;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Sanaei
{
    private ?string $cookie = null;
    private string $url;
    private string $apiBase;
    private string $cacheKey;
    private bool $verifySsl = false;
    private int $timeout = 30;
    private ?string $configHost = null;

    public array $loginStatus = [
        'status' => false,
        'message' => null,
    ];

    public function __construct(array $loginData)
    {
        $this->url = rtrim($loginData['url'], '/');

        if (!empty($loginData['web_base_path'])) {
            $this->url .= '/' . trim($loginData['web_base_path'], '/');
        }

        $this->apiBase = "{$this->url}/panel/api/inbounds";

        $this->verifySsl = $loginData['verify_ssl'] ?? false;
        $this->timeout = $loginData['timeout'] ?? 30;
        $this->configHost = $loginData['config_host'] ?? parse_url($loginData['url'], PHP_URL_HOST);

        $this->cacheKey = "sanaei-cookie-{$loginData['id']}";

        $cachedCookie = Cache::get($this->cacheKey);

        if (!empty($cachedCookie)) {
            $this->cookie = $cachedCookie;

            $this->loginStatus = [
                'status' => true,
                'message' => 'Session loaded from cache',
            ];

            return;
        }

        $result = $this->login(
            $loginData['username'],
            $loginData['password'],
            $loginData['twoFactorCode'] ?? null
        );

        if (!$result['status']) {
            $this->loginStatus = $result;
            return;
        }

        $this->loginStatus = [
            'status' => true,
            'message' => 'Login successful',
        ];
    }

    private function login(string $username, string $password, ?string $twoFactorCode = null): array
    {
        $payload = [
            'username' => $username,
            'password' => $password,
        ];

        if (!empty($twoFactorCode)) {
            $payload['twoFactorCode'] = $twoFactorCode;
        }

        $result = $this->request([
            'link' => "{$this->url}/login",
            'method' => 'POST',
            'endpoint' => 'login',
            'body_type' => 'form',
            'query' => $payload,
        ]);

        if (empty($this->cookie)) {
            return [
                'status' => false,
                'message' => $result['msg'] ?? $result['message'] ?? 'Login failed: cookie not received',
                'response' => $result,
            ];
        }

        Cache::put($this->cacheKey, $this->cookie, now()->addHours(2));

        return [
            'status' => true,
            'message' => 'Login successful',
        ];
    }

    public function request(array $data)
    {
        $url = $data['link'];
        $method = strtoupper($data['method'] ?? 'GET');
        $query = $data['query'] ?? [];
        $endpoint = $data['endpoint'] ?? null;
        $bodyType = $data['body_type'] ?? 'json';
        $raw = $data['raw'] ?? false;

        $headers = [
            'Accept: application/json',
        ];

        if ($endpoint !== 'login' && !empty($this->cookie)) {
            $headers[] = 'Cookie: ' . $this->cookie;
        }

        if ($method === 'GET' && !empty($query) && is_array($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $this->timeout,
        ];

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $options[CURLOPT_CUSTOMREQUEST] = $method;

            if ($bodyType === 'form') {
                $options[CURLOPT_POSTFIELDS] = is_array($query)
                    ? http_build_query($query)
                    : $query;

                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
            } else {
                $options[CURLOPT_POSTFIELDS] = is_array($query)
                    ? json_encode($query, JSON_UNESCAPED_UNICODE)
                    : $query;

                $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            }
        }

        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);

        if ($rawResponse === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'status' => false,
                'success' => false,
                'message' => 'cURL Error: ' . $error,
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);

        if ($endpoint === 'login') {
            $cookie = $this->extractCookie($responseHeaders);

            if (!empty($cookie)) {
                $this->cookie = $cookie;
            }
        }

        if ($raw) {
            return [
                'status' => $httpCode >= 200 && $httpCode < 300,
                'success' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'headers' => $responseHeaders,
                'body' => $responseBody,
            ];
        }

        $decoded = json_decode($responseBody, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => false,
                'success' => false,
                'http_code' => $httpCode,
                'message' => 'Invalid JSON response',
                'raw' => $responseBody,
            ];
        }

        if ($httpCode >= 400) {
            return [
                'status' => false,
                'success' => false,
                'http_code' => $httpCode,
                'message' => $decoded['msg'] ?? $decoded['message'] ?? 'Request failed',
                'response' => $decoded,
            ];
        }

        return $decoded;
    }

    private function extractCookie(string $headers): ?string
    {
        preg_match_all('/^Set-Cookie:\s*([^;]*)/mi', $headers, $matches);

        if (empty($matches[1])) {
            return null;
        }

        return implode('; ', $matches[1]);
    }

    private function getObj($response)
    {
        if (is_array($response) && array_key_exists('obj', $response)) {
            return $response['obj'];
        }

        return $response;
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function randomSubId(int $length = 16): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $result;
    }

    private function buildQuery(array $query): string
    {
        $query = array_filter($query, function ($value) {
            return $value !== null && $value !== '';
        });

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    private function getInboundSettings(array $inbound): array
    {
        return $this->decodeJson($inbound['settings'] ?? []);
    }

    private function getStreamSettings(array $inbound): array
    {
        return $this->decodeJson($inbound['streamSettings'] ?? []);
    }

    private function getInboundClients(array $inbound): array
    {
        $settings = $this->getInboundSettings($inbound);

        return $settings['clients'] ?? [];
    }

    private function findClient(array $inbound, string $identifier): ?array
    {
        foreach ($this->getInboundClients($inbound) as $client) {
            if (
                ($client['email'] ?? null) === $identifier ||
                ($client['id'] ?? null) === $identifier ||
                ($client['password'] ?? null) === $identifier ||
                ($client['subId'] ?? null) === $identifier
            ) {
                return $client;
            }
        }

        return null;
    }

    private function getClientEndpointId(array $inbound, array $client): ?string
    {
        $protocol = strtolower($inbound['protocol'] ?? '');

        if (in_array($protocol, ['vless', 'vmess'])) {
            return $client['id'] ?? null;
        }

        if ($protocol === 'trojan') {
            return $client['password'] ?? null;
        }

        if ($protocol === 'shadowsocks') {
            return $client['email'] ?? null;
        }

        return $client['id'] ?? $client['password'] ?? $client['email'] ?? null;
    }

    private function makeClientPayload(array $inbound, array $params): array
    {
        $protocol = strtolower($inbound['protocol'] ?? 'vless');

        $email = $params['email'] ?? 'client_' . time() . '_' . random_int(1000, 9999);

        $expiryTime = $params['expiryTime'] ?? null;

        if ($expiryTime === null) {
            $days = $params['days'] ?? 30;

            $expiryTime = $days > 0
                ? (int) (now()->addDays($days)->timestamp * 1000)
                : 0;
        }

        $totalBytes = $params['totalGB'] ?? null;

        if ($totalBytes === null) {
            $totalGB = $params['total_gb'] ?? 0;

            $totalBytes = $totalGB > 0
                ? (int) ($totalGB * 1024 * 1024 * 1024)
                : 0;
        }

        $client = [
            'email' => $email,
            'limitIp' => $params['limitIp'] ?? $params['limit_ip'] ?? 0,
            'totalGB' => $totalBytes,
            'expiryTime' => $expiryTime,
            'enable' => $params['enable'] ?? true,
            'tgId' => $params['tgId'] ?? '',
            'subId' => $params['subId'] ?? $this->randomSubId(),
            'reset' => $params['reset'] ?? 0,
        ];

        if (!empty($params['comment'])) {
            $client['comment'] = $params['comment'];
        }

        if ($protocol === 'vless') {
            $client['id'] = $params['id'] ?? $params['uuid'] ?? (string) Str::uuid();
            $client['flow'] = $params['flow'] ?? '';
        }

        if ($protocol === 'vmess') {
            $client['id'] = $params['id'] ?? $params['uuid'] ?? (string) Str::uuid();
            $client['alterId'] = $params['alterId'] ?? 0;
            $client['security'] = $params['security'] ?? 'auto';
        }

        if ($protocol === 'trojan') {
            $client['password'] = $params['password'] ?? (string) Str::uuid();
        }

        if ($protocol === 'shadowsocks') {
            $client['method'] = $params['method'] ?? null;
            $client['password'] = $params['password'] ?? Str::random(16);
        }

        return array_filter($client, function ($value) {
            return $value !== null;
        });
    }

    public function checkConnection(): bool
    {
        return !empty($this->cookie);
    }

    public function getLoginStatus(): array
    {
        return $this->loginStatus;
    }

    public function getCookie(): ?string
    {
        return $this->cookie;
    }

    public function clearSession(): bool
    {
        Cache::forget($this->cacheKey);
        $this->cookie = null;

        return true;
    }

    public function getInbounds()
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/list",
            'method' => 'GET',
        ]);
    }

    public function getInbound(int $inboundId)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/get/{$inboundId}",
            'method' => 'GET',
        ]);
    }

    public function addInbound(array $params)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/add",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function updateInbound(int $inboundId, array $params)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/update/{$inboundId}",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function deleteInbound(int $inboundId)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/del/{$inboundId}",
            'method' => 'POST',
        ]);
    }

    public function addClient(int $inboundId, array $client)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/addClient",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => [
                'id' => $inboundId,
                'settings' => json_encode([
                    'clients' => [$client],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    public function createClient(array $params): array
    {
        if (!$this->cookie) {
            return [
                'status' => false,
                'message' => 'Login failed',
            ];
        }

        $inboundId = $params['inbound_id'] ?? null;

        if (empty($inboundId)) {
            return [
                'status' => false,
                'message' => 'inbound_id is required',
            ];
        }

        $inboundResponse = $this->getInbound((int) $inboundId);
        $inbound = $this->getObj($inboundResponse);

        if (!is_array($inbound) || empty($inbound['id'])) {
            return [
                'status' => false,
                'message' => 'Inbound not found',
                'response' => $inboundResponse,
            ];
        }

        $client = $this->makeClientPayload($inbound, $params);

        $response = $this->addClient((int) $inboundId, $client);

        return [
            'status' => $response['success'] ?? false,
            'message' => $response['msg'] ?? null,
            'response' => $response,
            'inbound' => $inbound,
            'client' => $client,
        ];
    }

    public function updateClient(array $params)
    {
        if (!$this->cookie) {
            return false;
        }

        $inboundId = $params['inbound_id'] ?? null;
        $identifier = $params['identifier'] ?? $params['email'] ?? null;

        if (empty($inboundId)) {
            return [
                'status' => false,
                'message' => 'inbound_id is required',
            ];
        }

        if (empty($identifier)) {
            return [
                'status' => false,
                'message' => 'identifier or email is required',
            ];
        }

        $inboundResponse = $this->getInbound((int) $inboundId);
        $inbound = $this->getObj($inboundResponse);

        if (!is_array($inbound) || empty($inbound['id'])) {
            return [
                'status' => false,
                'message' => 'Inbound not found',
                'response' => $inboundResponse,
            ];
        }

        $oldClient = $this->findClient($inbound, $identifier);

        if (!$oldClient) {
            return [
                'status' => false,
                'message' => 'Client not found',
            ];
        }

        $endpointClientId = $this->getClientEndpointId($inbound, $oldClient);

        if (empty($endpointClientId)) {
            return [
                'status' => false,
                'message' => 'Client endpoint id not found',
            ];
        }

        $newClient = $oldClient;

        $fields = [
            'id',
            'password',
            'flow',
            'email',
            'limitIp',
            'totalGB',
            'expiryTime',
            'enable',
            'tgId',
            'subId',
            'reset',
            'alterId',
            'security',
            'method',
            'comment',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $params)) {
                $newClient[$field] = $params[$field];
            }
        }

        if (array_key_exists('limit_ip', $params)) {
            $newClient['limitIp'] = $params['limit_ip'];
        }

        if (array_key_exists('total_gb', $params)) {
            $newClient['totalGB'] = (int) ($params['total_gb'] * 1024 * 1024 * 1024);
        }

        if (array_key_exists('days', $params)) {
            $newClient['expiryTime'] = $params['days'] > 0
                ? (int) (now()->addDays($params['days'])->timestamp * 1000)
                : 0;
        }

        return $this->request([
            'link' => "{$this->apiBase}/updateClient/{$endpointClientId}",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => [
                'id' => (int) $inboundId,
                'settings' => json_encode([
                    'clients' => [$newClient],
                ], JSON_UNESCAPED_UNICODE),
            ],
        ]);
    }

    public function deleteClient(int $inboundId, string $clientId)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/{$inboundId}/delClient/{$clientId}",
            'method' => 'POST',
        ]);
    }

    public function deleteClientByEmail(int $inboundId, string $email)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/{$inboundId}/delClientByEmail/{$email}",
            'method' => 'POST',
        ]);
    }

    public function resetClientTraffic(int $inboundId, string $email)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/{$inboundId}/resetClientTraffic/{$email}",
            'method' => 'POST',
        ]);
    }

    public function getClientTraffics(string $email)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/getClientTraffics/{$email}",
            'method' => 'GET',
        ]);
    }

    public function getClientTrafficsByInboundId(int $inboundId)
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/getClientTrafficsById/{$inboundId}",
            'method' => 'GET',
        ]);
    }

    public function getOnlineClients()
    {
        if (!$this->cookie) {
            return false;
        }

        return $this->request([
            'link' => "{$this->apiBase}/onlines",
            'method' => 'POST',
        ]);
    }

    public function getClient(int $inboundId, string $identifier): array
    {
        $inboundResponse = $this->getInbound($inboundId);
        $inbound = $this->getObj($inboundResponse);

        if (!is_array($inbound) || empty($inbound['id'])) {
            return [
                'status' => false,
                'message' => 'Inbound not found',
                'response' => $inboundResponse,
            ];
        }

        $client = $this->findClient($inbound, $identifier);

        if (!$client) {
            return [
                'status' => false,
                'message' => 'Client not found',
            ];
        }

        return [
            'status' => true,
            'inbound' => $inbound,
            'client' => $client,
        ];
    }

    public function createClientAndGetConfig(array $params): array
    {
        $created = $this->createClient($params);

        if (empty($created['status'])) {
            return $created;
        }

        $inboundId = (int) $params['inbound_id'];
        $email = $created['client']['email'];

        $config = $this->getClientConfig($inboundId, $email, [
            'remark' => $params['remark'] ?? null,
            'config_host' => $params['config_host'] ?? null,
        ]);

        if (empty($config['status'])) {
            return [
                'status' => false,
                'message' => 'Client created but config generation failed',
                'created' => $created,
                'config' => $config,
            ];
        }

        return [
            'status' => true,
            'created' => $created['response'],
            'inbound' => $config['inbound'],
            'client' => $config['client'],
            'config' => $config['config'],
            'links' => $config['links'],
        ];
    }

    public function getClientConfig(int $inboundId, string $identifier, array $options = []): array
    {
        $data = $this->getClient($inboundId, $identifier);

        if (empty($data['status'])) {
            return $data;
        }

        $links = $this->buildClientLinks(
            $data['inbound'],
            $data['client'],
            $options
        );

        return [
            'status' => !empty($links),
            'inbound' => $data['inbound'],
            'client' => $data['client'],
            'links' => $links,
            'config' => implode("\n", $links),
        ];
    }

    private function buildClientLinks(array $inbound, array $client, array $options = []): array
    {
        $protocol = strtolower($inbound['protocol'] ?? '');

        if ($protocol === 'vless') {
            return [$this->buildVlessLink($inbound, $client, $options)];
        }

        if ($protocol === 'vmess') {
            return [$this->buildVmessLink($inbound, $client, $options)];
        }

        if ($protocol === 'trojan') {
            return [$this->buildTrojanLink($inbound, $client, $options)];
        }

        if ($protocol === 'shadowsocks') {
            return [$this->buildShadowsocksLink($inbound, $client, $options)];
        }

        return [];
    }

    private function getBaseConfigParts(array $inbound, array $client, array $options = []): array
    {
        $stream = $this->getStreamSettings($inbound);

        $host = $options['config_host'] ?? $this->configHost;
        $port = $options['port'] ?? $inbound['port'];
        $network = $stream['network'] ?? 'tcp';
        $security = $stream['security'] ?? 'none';

        $remark = $options['remark']
            ?? trim(($inbound['remark'] ?? 'config') . '-' . ($client['email'] ?? ''));

        return [
            'stream' => $stream,
            'host' => $host,
            'port' => $port,
            'network' => $network,
            'security' => $security,
            'remark' => $remark,
        ];
    }

    private function getStreamQuery(array $stream): array
    {
        $network = $stream['network'] ?? 'tcp';
        $security = $stream['security'] ?? 'none';

        $query = [
            'type' => $network,
            'security' => $security ?: 'none',
        ];

        if ($network === 'ws') {
            $ws = $stream['wsSettings'] ?? [];

            $query['path'] = $ws['path'] ?? '/';
            $query['host'] = $ws['headers']['Host'] ?? null;
        }

        if ($network === 'grpc') {
            $grpc = $stream['grpcSettings'] ?? [];

            $query['serviceName'] = $grpc['serviceName'] ?? '';
            $query['mode'] = $grpc['multiMode'] ?? null;
            $query['authority'] = $grpc['authority'] ?? null;
        }

        if ($network === 'tcp') {
            $tcp = $stream['tcpSettings'] ?? [];
            $header = $tcp['header'] ?? [];

            if (($header['type'] ?? '') === 'http') {
                $query['headerType'] = 'http';
                $query['host'] = $header['request']['headers']['Host'][0] ?? null;
                $query['path'] = $header['request']['path'][0] ?? null;
            }
        }

        if ($network === 'httpupgrade') {
            $http = $stream['httpupgradeSettings'] ?? [];

            $query['path'] = $http['path'] ?? '/';
            $query['host'] = $http['host'] ?? null;
        }

        if ($security === 'tls') {
            $tls = $stream['tlsSettings'] ?? [];

            $query['sni'] = $tls['serverName'] ?? null;
            $query['fp'] = $tls['fingerprint'] ?? null;

            if (!empty($tls['alpn']) && is_array($tls['alpn'])) {
                $query['alpn'] = implode(',', $tls['alpn']);
            }
        }

        if ($security === 'reality') {
            $reality = $stream['realitySettings'] ?? [];

            $query['security'] = 'reality';
            $query['sni'] = $reality['serverNames'][0] ?? $reality['serverName'] ?? null;
            $query['fp'] = $reality['fingerprint'] ?? 'chrome';
            $query['pbk'] = $reality['publicKey'] ?? null;
            $query['sid'] = $reality['shortIds'][0] ?? null;
            $query['spx'] = $reality['spiderX'] ?? null;
        }

        return $query;
    }

    private function buildVlessLink(array $inbound, array $client, array $options = []): string
    {
        $parts = $this->getBaseConfigParts($inbound, $client, $options);
        $query = $this->getStreamQuery($parts['stream']);

        $query['encryption'] = 'none';

        if (!empty($client['flow'])) {
            $query['flow'] = $client['flow'];
        }

        return 'vless://'
            . $client['id']
            . '@'
            . $parts['host']
            . ':'
            . $parts['port']
            . '?'
            . $this->buildQuery($query)
            . '#'
            . rawurlencode($parts['remark']);
    }

    private function buildVmessLink(array $inbound, array $client, array $options = []): string
    {
        $parts = $this->getBaseConfigParts($inbound, $client, $options);
        $query = $this->getStreamQuery($parts['stream']);

        $vmess = [
            'v' => '2',
            'ps' => $parts['remark'],
            'add' => $parts['host'],
            'port' => (string) $parts['port'],
            'id' => $client['id'],
            'aid' => (string) ($client['alterId'] ?? 0),
            'scy' => $client['security'] ?? 'auto',
            'net' => $parts['network'],
            'type' => $query['headerType'] ?? 'none',
            'host' => $query['host'] ?? '',
            'path' => $query['path'] ?? '',
            'tls' => $parts['security'] === 'tls' ? 'tls' : '',
            'sni' => $query['sni'] ?? '',
            'alpn' => $query['alpn'] ?? '',
            'fp' => $query['fp'] ?? '',
        ];

        return 'vmess://' . base64_encode(json_encode($vmess, JSON_UNESCAPED_UNICODE));
    }

    private function buildTrojanLink(array $inbound, array $client, array $options = []): string
    {
        $parts = $this->getBaseConfigParts($inbound, $client, $options);
        $query = $this->getStreamQuery($parts['stream']);

        return 'trojan://'
            . $client['password']
            . '@'
            . $parts['host']
            . ':'
            . $parts['port']
            . '?'
            . $this->buildQuery($query)
            . '#'
            . rawurlencode($parts['remark']);
    }

    private function buildShadowsocksLink(array $inbound, array $client, array $options = []): string
    {
        $parts = $this->getBaseConfigParts($inbound, $client, $options);
        $settings = $this->getInboundSettings($inbound);

        $method = $client['method'] ?? $settings['method'] ?? 'chacha20-ietf-poly1305';
        $password = $client['password'] ?? '';

        $userInfo = base64_encode($method . ':' . $password);

        return 'ss://'
            . $userInfo
            . '@'
            . $parts['host']
            . ':'
            . $parts['port']
            . '#'
            . rawurlencode($parts['remark']);
    }
}
