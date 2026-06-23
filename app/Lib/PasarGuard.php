<?php

namespace App\Lib;

use Illuminate\Support\Facades\Cache;

class PasarGuard
{
    private $token = null;
    private $url = null;
    private $cacheKey = null;
    private $verifySsl = false;

    public $loginStatus = [
        'status' => false,
        'message' => null,
    ];

    public function __construct(array $loginData)
    {
        $this->url = rtrim($loginData['url'], '/');
        $this->verifySsl = $loginData['verify_ssl'] ?? false;

        $this->cacheKey = "pasarguard-token-{$loginData['id']}";

        $cachedToken = Cache::get($this->cacheKey);

        if (!empty($cachedToken)) {
            $this->token = $cachedToken;

            $this->loginStatus = [
                'status' => true,
                'message' => 'Token loaded from cache',
            ];

            return;
        }

        $result = $this->request([
            'link' => "{$this->url}/api/admin/token",
            'method' => 'POST',
            'endpoint' => 'login',
            'body_type' => 'form',
            'query' => [
                'username' => $loginData['username'],
                'password' => $loginData['password'],
            ],
        ]);

        if (!is_array($result)) {
            $this->token = false;

            $this->loginStatus = [
                'status' => false,
                'message' => 'Invalid response from PasarGuard',
            ];

            return;
        }

        if (isset($result['detail'])) {
            $this->token = false;

            $this->loginStatus = [
                'status' => false,
                'message' => is_array($result['detail'])
                    ? json_encode($result['detail'], JSON_UNESCAPED_UNICODE)
                    : $result['detail'],
            ];

            return;
        }

        if (empty($result['access_token'])) {
            $this->token = false;

            $this->loginStatus = [
                'status' => false,
                'message' => 'Access token not found',
            ];

            return;
        }

        $this->token = $result['access_token'];

        Cache::put($this->cacheKey, $this->token, now()->addHours(2));

        $this->loginStatus = [
            'status' => true,
            'message' => 'Login successful',
        ];
    }

    public function request(array $data)
    {
        $url = $data['link'];
        $query = $data['query'] ?? [];
        $method = strtoupper($data['method'] ?? 'GET');
        $endpoint = $data['endpoint'] ?? null;
        $bodyType = $data['body_type'] ?? 'json';
        $raw = $data['raw'] ?? false;

        $headers = [
            'Accept: application/json',
        ];

        if ($endpoint !== 'login' && !empty($this->token)) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        if ($method === 'GET' && !empty($query) && is_array($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
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

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'status' => false,
                'message' => 'cURL Error: ' . $error,
            ];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        curl_close($ch);

        if ($raw) {
            return [
                'status' => $httpCode >= 200 && $httpCode < 300,
                'http_code' => $httpCode,
                'content_type' => $contentType,
                'body' => $response,
            ];
        }

        $decoded = json_decode($response, true);

        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'status' => false,
                'http_code' => $httpCode,
                'message' => 'Invalid JSON response',
                'raw' => $response,
            ];
        }

        if ($httpCode >= 400) {
            return [
                'status' => false,
                'http_code' => $httpCode,
                'message' => $decoded['detail'] ?? 'Request failed',
                'response' => $decoded,
            ];
        }

        return $decoded;
    }

    /* Connection */

    public function checkConnection()
    {
        return !empty($this->token);
    }

    public function getToken()
    {
        return $this->token;
    }

    public function getLoginStatus()
    {
        return $this->loginStatus;
    }

    public function clearTokenCache()
    {
        if (!empty($this->cacheKey)) {
            Cache::forget($this->cacheKey);
        }

        $this->token = null;

        return true;
    }

    /* Groups */

    public function getGroups()
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/groups",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function singleGroup($groupId)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/group/{$groupId}",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    /* Users */

    public function getAllUsers()
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/users",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function getSimpleUsers()
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/users/simple",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function getUser($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function getUserById($userId)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/by-id/{$userId}",
            'method' => 'GET',
            'query' => [],
        ]);
    }

    public function createUser(array $params)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function createUserFromTemplate(array $params)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/from_template",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function updateUser(array $params)
    {
        if (!$this->token) {
            return false;
        }

        if (empty($params['username'])) {
            return [
                'status' => false,
                'message' => 'username is required',
            ];
        }

        $username = $params['username'];

        unset($params['username']);

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}",
            'method' => 'PUT',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function updateUserById($userId, array $params)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/by-id/{$userId}",
            'method' => 'PUT',
            'body_type' => 'json',
            'query' => $params,
        ]);
    }

    public function deleteUser($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}",
            'method' => 'DELETE',
            'body_type' => 'json',
            'query' => [],
        ]);
    }

    public function resetUserUsage($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}/reset",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => [],
        ]);
    }

    public function revokeSubscription($username)
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$username}/revoke_sub",
            'method' => 'POST',
            'body_type' => 'json',
            'query' => [],
        ]);
    }

    /* Subscription / Config */

    public function getUserConfig($userId, $clientType = 'links')
    {
        if (!$this->token) {
            return false;
        }

        return $this->request([
            'link' => "{$this->url}/api/user/{$userId}/subscription/{$clientType}",
            'method' => 'GET',
            'query' => [],
            'raw' => true,
        ]);
    }

    public function getUserLinks($userId)
    {
        return $this->getUserConfig($userId, 'links');
    }

    public function getUserLinksBase64($userId)
    {
        return $this->getUserConfig($userId, 'links_base64');
    }

    public function getUserClash($userId)
    {
        return $this->getUserConfig($userId, 'clash');
    }

    public function getUserClashMeta($userId)
    {
        return $this->getUserConfig($userId, 'clash_meta');
    }

    public function getUserSingBox($userId)
    {
        return $this->getUserConfig($userId, 'sing_box');
    }

    public function getUserXray($userId)
    {
        return $this->getUserConfig($userId, 'xray');
    }

    public function createUserAndGetConfig(array $params)
    {
        if (!$this->token) {
            return [
                'status' => false,
                'message' => 'PasarGuard login failed',
                'login' => $this->loginStatus,
            ];
        }

        if (empty($params['username'])) {
            return [
                'status' => false,
                'message' => 'username is required',
            ];
        }

        if (empty($params['group_id'])) {
            return [
                'status' => false,
                'message' => 'group_id is required',
            ];
        }

        $username = $params['username'];
        $groupId = $params['group_id'];
        $days = $params['days'] ?? 30;
        $totalGB = $params['total_gb'] ?? 0;
        $clientType = $params['client_type'] ?? 'links';

        $dataLimit = $totalGB > 0
            ? $totalGB * 1024 * 1024 * 1024
            : 0;

        $createParams = [
            'username' => $username,
            'status' => $params['status'] ?? 'active',
            'data_limit' => $params['data_limit'] ?? $dataLimit,
            'data_limit_reset_strategy' => $params['data_limit_reset_strategy'] ?? 'no_reset',
            'group_ids' => $groupId,
            'auto_delete_in_days' => $params['auto_delete_in_days'] ?? 7,
        ];

        if ($params['status'] == 'on_hold') {
            $createParams['on_hold_expire_duration'] = $params['on_hold_expire_duration'] ?? now()->addDays($days)->utc()->toISOString();
            $createParams['on_hold_timeout'] = $params['on_hold_timeout'] ?? 0;
        }else{
            $createParams['expire'] = $params['expire'] ?? now()->addDays($days)->utc()->toISOString();
        }

        if (array_key_exists('note', $params)) {
            $createParams['note'] = $params['note'];
        }

        if (array_key_exists('proxy_settings', $params)) {
            $createParams['proxy_settings'] = $params['proxy_settings'];
        }

        $user = $this->createUser($createParams);

        if (!is_array($user)) {
            return [
                'status' => false,
                'message' => 'User creation failed',
                'response' => $user,
            ];
        }

        if (isset($user['status']) && $user['status'] === false) {
            return [
                'status' => false,
                'message' => $user['message'] ?? 'User creation failed',
                'response' => $user,
            ];
        }

        $userId = $user['id'] ?? $user['user']['id'] ?? null;

        if (empty($userId)) {
            return [
                'status' => false,
                'message' => 'User created but user id not found in response',
                'response' => $user,
            ];
        }

        $config = $this->getUserConfig($userId, $clientType);

        if (!is_array($config) || empty($config['status'])) {
            return [
                'status' => false,
                'message' => 'Config generation failed',
                'user' => $user,
                'config_response' => $config,
            ];
        }

        return [
            'status' => true,
            'user' => $user,
            'user_id' => $userId,
            'config_type' => $clientType,
            'config' => $config['body'],
            'subscription_url' => $user['subscription_url'] ?? $user['user']['subscription_url'] ?? null,
        ];
    }
}
