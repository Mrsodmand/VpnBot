<?php

namespace App\lib;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PasarGuard
{

    private $token;

    public function __construct()
    {
        $this->token = Cache::remember('token', now()->addHours(2), function () {
            $data['link'] = "https://multi.ipsabet.app/api/admin/token";
            $data['query'] = http_build_query([
                'grant_type' => 'password',
                'username' => env('PASAR_USERNAME'),
                'password' => env('PASAR_PASSWORD'),
                'scope' => null,
                'client_id' => 'string',
            ]);
            $data['method'] = 'post';
            $data['endpoint'] = 'login';
            return $this->request($data)['access_token'];
        });
    }


    public function request(array $data)
    {

        $url = $data['link'];
        $query = $data['query'] ?? [];
        $method = strtoupper($data['method'] ?? 'GET');
        $endpoint = isset($data['endpoint']) ?? null;

        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $this->token,
            ],
        ];

        if ($endpoint != 'login') {
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }


        // اگر متد POST / PUT / PATCH بود
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = $query;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        }

        // اگر GET بود
        if ($method === 'GET' && !empty($query)) {
            $options[CURLOPT_URL] .= '?' . http_build_query($query);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception('cURL Error: ' . curl_error($ch));
        }

        curl_close($ch);
        return json_decode($response, true);
    }

    /* Groups */
    public function getGroups()
    {
        $data['link'] = 'https://multi.ipsabet.app/api/groups';
        $data['method'] = 'get';
        $data['query'] = [];
        return $this->request($data);
    }

    public function singleGroup($params)
    {
        $id = $params['id'];
        $data['link'] = "https://multi.ipsabet.app/api/group/$id";
        $data['method'] = 'get';
        $data['query'] = [];

        return $this->request($data);
    }

    /* Users */
    public function getAllUsers()
    {
        $data['link'] = 'https://multi.ipsabet.app/api/users';
        $data['query'] = http_build_query([]);
        $data['method'] = 'get';

        dd($this->request($data));

    }

    public function getUser($params)
    {

        $data['link'] = 'https://multi.ipsabet.app/api/user/'.$params;
        $data['method'] = 'get';
        $data['query'] = null;

        return $this->request($data);
    }

    public function createUser($params)
    {

        $data['link'] = 'https://multi.ipsabet.app/api/user';
        $data['method'] = 'post';
        $data['query'] = json_encode($params);
        return $this->request($data);

    }

    public function updateUser($params)
    {

        $data['link'] = 'https://multi.ipsabet.app/api/user/' . $params['username'];
        $data['method'] = 'put';
        $data['query'] = json_encode($params);
        return $this->request($data);

    }
}
