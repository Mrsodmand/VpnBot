<?php

use App\Models\Panels;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

if (!function_exists('PersianNumToEn')) {
    function PersianNumToEn($input)
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '٤', '۵', '٥', '٦', '۶', '۷', '۸', '۹'];
        $english = [0, 1, 2, 3, 4, 4, 5, 5, 6, 6, 7, 8, 9];
        return str_replace($persian, $english, $input);
    }
}

if (!function_exists('loginToSanaie')) {
    function loginToSanaie($data)
    {

        $panel = Panels::where('url', $data['url'])->first();
        if (!is_null($panel->detail)) {
            $expire = $panel->detail['Expires'];
            if ($expire > Carbon::now()) {
                $Data = [
                    'Domain' => $panel->detail['Domain'],
                    'Path' => $panel->detail['Path'],
                ];

                return [
                    'status' => true,
                    'cookies' => $panel->detail['cookies'],
                    'session' => $panel->detail['session'],
                    'raw' => $Data,
                ];
            }
        }


        $baseUrl = rtrim($data['url'], '/') . '/login';

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $data['username'],
                'password' => $data['password'],
            ]),

            // مهم برای گرفتن کوکی
            CURLOPT_HEADER => true,

            // SSL bypass
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,

            // timeout
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 20,

            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        /*
        |--------------------------------------------------------------------------
        | Parse response
        |--------------------------------------------------------------------------
        */

        $header = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        /*
        |--------------------------------------------------------------------------
        | Extract cookies
        |--------------------------------------------------------------------------
        */

        preg_match_all('/Set-Cookie:\s*([^;]*)/mi', $header, $matches);

        $cookies = $matches[1] ?? [];

        $sessionCookie = null;
        $Data = [];

        foreach ($cookies as $cookie) {

            if (str_contains($cookie, 'session') || str_contains($cookie, '3x-ui')) {
                $sessionCookie = $cookie;
            }

            $parts = explode('=', $cookie, 2);

            $Data = [
                'name' => $parts[0] ?? null,
                'value' => $parts[1] ?? null,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Check login success
        |--------------------------------------------------------------------------
        */

        if ($httpCode !== 200 || empty($sessionCookie)) {
            return [
                'status' => false,
                'message' => 'Login failed',
                'http_code' => $httpCode,
                'error' => $error,
                'body' => $body,
            ];
        }

        /*
        |--------------------------------------------------------------------------
        | Save panel session
        |--------------------------------------------------------------------------
        */

        $panel->detail = [
            'session' => $sessionCookie,
            'cookies' => $cookies,
            'Domain' => parse_url($data['url'], PHP_URL_HOST),
        ];

        $panel->save();

        /*
        |--------------------------------------------------------------------------
        | Return result
        |--------------------------------------------------------------------------
        */

        return [
            'status' => true,
            'session' => $sessionCookie,
            'cookies' => $cookies,
            'raw' => $Data,
            'http_code' => $httpCode,
        ];
    }
}

if (!function_exists('createUser')) {
    function createUser($data)
    {
        /*
          Expected $data:
          [
            'url' => 'https://your-server.com',
            'session' => 'session_cookie_value',
            'inbound_id' => 1,
            'email' => 'user@example.com',
            'uuid' => 'generated-uuid',
            'total_gb' => 10,
            'expiry_time' => timestamp (ms),
          ]
        */

        $baseUrl = rtrim($data['url'], '/');

        $payload = [
            "id" => $data['inbound_id'],
            "settings" => json_encode([
                "clients" => [
                    [
                        "id" => $data['uuid'],
                        "email" => $data['email'],
                        "limitIp" => 0,
                        "totalGB" => $data['total_gb'] * 1024 * 1024 * 1024,
                        "expiryTime" => $data['expiry_time'],
                        "enable" => true,
                        "tgId" => "",
                        "subId" => ""
                    ]
                ]
            ], JSON_UNESCAPED_SLASHES),
        ];

        $response = Http::withOptions([
            'verify' => false,
        ])->withHeaders([
            'Cookie' => $data['session'],
            'Content-Type' => 'application/json',
        ])->post($baseUrl . '/panel/api/inbounds/addClient', $payload);

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to create user',
                'http_code' => $response->status(),
                'response' => $response->body(),
            ];
        }

        return [
            'status' => true,
            'message' => 'User created successfully',
            'data' => $response->json(),
        ];
    }
}

if (!function_exists('getInbounds')) {

    function getInbounds($data)
    {
        $baseUrl = rtrim($data['url'], '/');

        $response = Http::withOptions([
            'verify' => false,
        ])
            ->timeout(60)
            ->connectTimeout(20)
            ->withHeaders([
                'Cookie' => $data['session'],
            ])->get($baseUrl . '/panel/api/inbounds/list');

        if (!$response->successful()) {
            return [
                'status' => false,
                'message' => 'Failed to fetch inbounds',
                'http_code' => $response->status(),
                'response' => $response->body(),
            ];
        }

        $result = $response->json();
        return [
            'status' => true,
            'inbounds' => $result['obj'] ?? [],
        ];
    }
}

if (!function_exists('calculateDiscount')) {
    function calculateExtraDiscount($plan, $pricePerGb)
    {

        $basePrice = $plan->name * $pricePerGb;

        $discount = $plan->discount ?? 0;

        $price = $basePrice - ($basePrice * $discount / 100);

        return [
            'base-price' => $basePrice,
            'price' => $price,
        ];
    }
}

if (!function_exists('headTitle')) {
    function headTitle($title)
    {
        $text = "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $text .= "<b>{$title} </b>\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        return $text;
    }
}

if (!function_exists('generateConfig')) {

    function generateConfig(array $inbound, array $client, $address)
    {
        $protocol = strtolower($inbound['protocol']);
        $network = $inbound['streamSettings']['network'] ?? 'tcp';
        $security = $inbound['streamSettings']['security'] ?? 'none';

        $port = $inbound['port'];

        switch ($protocol) {

            case 'vless':
                return buildVless($inbound, $client, $address, $port);

            case 'vmess':
                return buildVmess($inbound, $client, $address, $port);

            case 'trojan':
                return buildTrojan($inbound, $client, $address, $port);

            default:
                return null;
        }
    }

    function buildVless($inbound, $client, $server, $port)
    {
        $uuid = $client['uuid'];
        $remark = $client['email'];

        $network = $inbound['streamSettings']['network'] ?? 'tcp';
        $security = $inbound['streamSettings']['security'] ?? 'none';

        $query = [
            'type' => $network,
            'encryption' => 'none',
            'security' => $security,
            'fp' => 'chrome',
        ];

        // WS
        if ($network === 'ws') {
            $query['host'] = $inbound['streamSettings']['wsSettings']['host'] ?? '';
            $query['path'] = $inbound['streamSettings']['wsSettings']['path'] ?? '/';
            $query['alpn'] = implode(',', $inbound['streamSettings']['tlsSettings']['alpn'] ?? []);
        }

        // TCP + TLS
        if ($network === 'tcp' && $security === 'tls') {
            $query['sni'] = $server;
        }

        // gRPC
        if ($network === 'grpc') {
            $query['serviceName'] = $inbound['streamSettings']['grpcSettings']['serviceName'] ?? '';
        }

        // REALITY
        if ($security === 'reality') {
            $query['pbk'] = $inbound['streamSettings']['realitySettings']['publicKey'] ?? '';
            $query['sid'] = $inbound['streamSettings']['realitySettings']['shortId'] ?? '';
            $query['spx'] = '/';
            $query['flow'] = 'xtls-rprx-vision';
        }

        return "vless://{$uuid}@{$server}:{$port}?" . http_build_query($query) . "#{$remark}";
    }

}

if (!function_exists('generateVlessConfig')) {

    function generateVlessConfig($inbound, $clientRemark, $serverAddress)
    {


        $uuid = $clientRemark['id'];
        $port = $inbound['port'];

        $stream = $inbound['streamSettings'] ?? [];

        $network = $stream['network'] ?? 'tcp';
        $security = $stream['security'] ?? 'none';

        $query = [
            'type' => $network,
            'encryption' => 'none',
        ];

        /*
        |--------------------------------------------------------------------------
        | TLS
        |--------------------------------------------------------------------------
        */

        if ($security !== 'none') {

            $query['security'] = $security;

            $tls = $stream['tlsSettings'] ?? [];

            if (!empty($tls['alpn'])) {
                $query['alpn'] = implode(',', $tls['alpn']);
            }

            if (!empty($tls['settings']['fingerprint'])) {
                $query['fp'] = $tls['settings']['fingerprint'];
            }

            if (!empty($tls['serverName'])) {
                $query['sni'] = $tls['serverName'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | WS
        |--------------------------------------------------------------------------
        */

        if ($network === 'ws') {

            $ws = $stream['wsSettings'] ?? [];

            $query['path'] = $ws['path'] ?? '/';

            if (!empty($ws['host'])) {
                $query['host'] = $ws['host'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | gRPC
        |--------------------------------------------------------------------------
        */

        if ($network === 'grpc') {

            $grpc = $stream['grpcSettings'] ?? [];

            if (!empty($grpc['serviceName'])) {
                $query['serviceName'] = $grpc['serviceName'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | TCP
        |--------------------------------------------------------------------------
        */

        if ($network === 'tcp') {

            $tcp = $stream['tcpSettings'] ?? [];

            if (!empty($tcp['header']['type'])) {
                $query['headerType'] = $tcp['header']['type'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Reality
        |--------------------------------------------------------------------------
        */

        if ($security === 'reality') {

            $reality = $stream['realitySettings'] ?? [];

            if (!empty($reality['publicKey'])) {
                $query['pbk'] = $reality['publicKey'];
            }

            if (!empty($reality['shortIds'][0])) {
                $query['sid'] = $reality['shortIds'][0];
            }

            if (!empty($reality['serverNames'][0])) {
                $query['sni'] = $reality['serverNames'][0];
            }
        }

        $queryString = http_build_query($query);

        return "vless://{$uuid}@{$serverAddress}:{$port}?{$queryString}#{$clientRemark['remark']}";
    }
}


function addClient($data)
{

    $serverUrl = $data['serverUrl'];
    $sessionCookie = $data['sessionCookie'];
    $inboundId = $data['inboundId'];
    $email = $data['email'];
    $uuid = $data['uuid'];
    $expiryTimestamp = $data['expiryTimestamp'];
    $limitIp = $data['limitIp'];
    $subId = $data['subId'];
    $totalGB = $data['totalGB'];

    $url = rtrim($serverUrl, '/') . '/panel/api/inbounds/addClient';

    $data = [
        "id" => $inboundId,
        "settings" => json_encode([
            "clients" => [
                [
                    "id" => $uuid,
                    "email" => $email,
                    "limitIp" => $limitIp,
                    "subId" => $subId,
                    "totalGB" => $totalGB,
                    "expiryTime" => $expiryTimestamp,
                    "enable" => true,
                ]
            ]
        ])
    ];

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "Cookie: $sessionCookie"
        ],
    ]);

    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        return [
            "status" => false,
            "error" => $error
        ];
    }

    curl_close($ch);

    return json_decode($response, true);
}


function getClient($data)
{

    $serverUrl = $data['serverUrl'];
    $sessionCookie = $data['sessionCookie'];
    $uuid = $data['uuid'];

    $url = rtrim($serverUrl, '/') . '/panel/api/inbounds/getClientTrafficsById/' . $uuid;
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded",
            "Cookie: $sessionCookie",
            "3x-ui: $sessionCookie"
        ],
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            "status" => false,
            "error" => $error
        ];
    }

    curl_close($ch);

    return json_decode($response, true);
}


function byteToGb($bytes, $precision = 2)
{
    return round($bytes / (1024 * 1024 * 1024), $precision);
}

function gbToByte($gb)
{
    return $gb * 1024 * 1024 * 1024;
}

function daysToMilliseconds($days)
{
    return $days * 24 * 60 * 60 * 1000;
}
