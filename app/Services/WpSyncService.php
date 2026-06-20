<?php

namespace App\Services;

use App\Models\Orders;
use App\Models\Payment;
use App\Models\Panels;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WpSyncService
{
    public function secret(): string
    {
        return (string) env('WP_SYNC_SECRET', '');
    }

    public function wpBaseUrl(): string
    {
        return rtrim((string) env('WP_BASE_URL', 'https://ip-sabet.me'), '/');
    }

    public function authorize(?string $key): bool
    {
        $secret = $this->secret();
        return $secret !== '' && is_string($key) && hash_equals($secret, $key);
    }

    public function confirmWordPressLink(User $telegramUser, string $code): array
    {
        $payload = [
            'code' => trim($code),
            'tel_id' => (string) $telegramUser->tel_id,
            'telegram' => [
                'username' => $telegramUser->username,
                'first_name' => $telegramUser->first_name,
                'last_name' => $telegramUser->last_name,
            ],
        ];

        $response = Http::timeout(20)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['X-IPSABET-API-KEY' => $this->secret()])
            ->post($this->wpBaseUrl() . '/wp-json/ipsvp/v1/sync/confirm-link', $payload);

        if (!$response->successful()) {
            return [
                'ok' => false,
                'message' => $response->json('message') ?: ('خطای اتصال به سایت: HTTP ' . $response->status()),
                'body' => $response->body(),
            ];
        }

        $data = $response->json();
        if (empty($data['ok'])) {
            return ['ok' => false, 'message' => $data['message'] ?? 'اتصال حساب تایید نشد.'];
        }

        $phone = (string) ($data['phone'] ?? '');
        $this->upsertLink($telegramUser, $phone, (int) ($data['site_user_id'] ?? 0));

        if (!empty($data['orders']) && is_array($data['orders'])) {
            foreach ($data['orders'] as $order) {
                $this->importSiteOrder($order, $telegramUser);
            }
        }

        return ['ok' => true, 'phone' => $phone, 'orders_count' => count($data['orders'] ?? [])];
    }

    public function upsertLink(User $user, string $phone, int $siteUserId = 0): void
    {
        DB::table('wp_sync_links')->updateOrInsert(
            ['tel_id' => (string) $user->tel_id],
            [
                'user_id' => $user->id,
                'phone' => $phone,
                'site_user_id' => $siteUserId ?: null,
                'linked_at' => now(),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    public function linkedUserByPhone(?string $phone): ?User
    {
        if (!$phone) {
            return null;
        }
        $link = DB::table('wp_sync_links')->where('phone', $phone)->first();
        return $link ? User::find($link->user_id) : null;
    }

    public function linkedUserByTelId(?string $telId): ?User
    {
        if (!$telId) {
            return null;
        }
        $link = DB::table('wp_sync_links')->where('tel_id', (string) $telId)->first();
        if ($link && $link->user_id) {
            return User::find($link->user_id);
        }
        return User::where('tel_id', (string) $telId)->first();
    }

    private function findPanelForSiteOrder(array $siteOrder): ?Panels
    {
        $urls = [];
        foreach (['panel_url', 'panel_url_raw'] as $key) {
            $url = trim((string) ($siteOrder[$key] ?? ''));
            if ($url !== '') {
                $urls[] = $url;
                $urls[] = rtrim($url, '/');
                $urls[] = rtrim($url, '/') . '/';
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));
        foreach ($urls as $url) {
            $panel = Panels::where('url', $url)->first();
            if ($panel) {
                return $panel;
            }
        }

        return null;
    }

    public function importSiteOrder(array $siteOrder, ?User $fallbackUser = null): ?Orders
    {
        $siteOrderId = (int) ($siteOrder['site_order_id'] ?? 0);
        if ($siteOrderId <= 0) {
            return null;
        }

        $user = $fallbackUser;
        if (!$user && !empty($siteOrder['phone'])) {
            $user = $this->linkedUserByPhone((string) $siteOrder['phone']);
        }
        if (!$user) {
            return null;
        }

        $mapping = DB::table('wp_sync_site_orders')->where('site_order_id', $siteOrderId)->first();
        $detail = [
            'source' => 'wordpress',
            'site_order_id' => $siteOrderId,
            'order_code' => $siteOrder['order_code'] ?? '',
            'phone' => $siteOrder['phone'] ?? '',
            'country' => trim(($siteOrder['country_flag'] ?? '') . ' ' . ($siteOrder['country_name'] ?? '')),
            'price' => (int) ($siteOrder['price'] ?? 0),
            'subscription_url' => $siteOrder['subscription_url'] ?? '',
            'links' => $siteOrder['links'] ?? [],
            'code' => $siteOrder['pg_username'] ?? ($siteOrder['order_code'] ?? ''),
            'pg_user_id' => $siteOrder['pg_user_id'] ?? '',
            'pg_status' => $siteOrder['pg_status'] ?? '',
            'panel_url' => $siteOrder['panel_url'] ?? '',
            'panel_url_raw' => $siteOrder['panel_url_raw'] ?? '',
        ];
        $panel = $this->findPanelForSiteOrder($siteOrder);
        if ($panel) {
            $detail['panel_id'] = $panel->id;
            $detail['panel_name'] = $panel->name ?? null;
        }
        $expireAt = !empty($siteOrder['approved_at']) ? Carbon::parse($siteOrder['approved_at'])->addDays((int) ($siteOrder['days'] ?? 0)) : now()->addDays(max(1, (int) ($siteOrder['days'] ?? 1)));

        $payload = [
            'user_id' => $user->id,
            'remark' => $siteOrder['pg_username'] ?: ($siteOrder['order_code'] ?? ('SITE-' . $siteOrderId)),
            'uid' => (string) ($siteOrder['pg_user_id'] ?? ''),
            'sub_id' => (string) ($siteOrder['subscription_url'] ?? ''),
            'plan' => trim(($siteOrder['plan_title'] ?? '') . ' | ' . ((int) ($siteOrder['gb'] ?? 0)) . 'GB / ' . ((int) ($siteOrder['days'] ?? 0)) . ' روز'),
            'status' => (string) ($siteOrder['status'] ?? 'created'),
            'panel_id' => $panel?->id,
            'inbound_id' => 0,
            'system_type' => 'wordpress',
            'detail' => $detail,
            'expire_at' => $expireAt,
            'updated_at' => now(),
        ];

        if ($mapping && $mapping->bot_order_id) {
            $order = Orders::find($mapping->bot_order_id);
            if ($order) {
                $order->fill($payload);
                $order->save();
                return $order;
            }
        }

        $payload['created_at'] = now();
        $order = Orders::create($payload);
        DB::table('wp_sync_site_orders')->updateOrInsert(
            ['site_order_id' => $siteOrderId],
            [
                'bot_order_id' => $order->id,
                'user_id' => $user->id,
                'phone' => (string) ($siteOrder['phone'] ?? ''),
                'order_code' => (string) ($siteOrder['order_code'] ?? ''),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
        return $order;
    }

    public function formatOrder(Orders $order): array
    {
        return [
            'id' => $order->id,
            'remark' => $order->remark,
            'plan' => $order->plan,
            'status' => $order->status,
            'status_label' => $this->statusLabel($order->status),
            'expire_at' => optional($order->expire_at)->toDateTimeString(),
            'source' => $order->system_type ?: 'bot',
            'detail' => $order->detail,
        ];
    }

    public function statusLabel(?string $status): string
    {
        return match ((string) $status) {
            'created', 'active' => 'فعال',
            'on_hold' => 'در انتظار اتصال',
            'expired' => 'منقضی شده',
            'disabled' => 'غیرفعال',
            default => (string) $status,
        };
    }

    public function creditWallet(User $user, int $amount, array $meta = []): int
    {
        $amount = max(0, $amount);
        if ($amount <= 0) {
            return (int) $user->balance;
        }
        DB::transaction(function () use ($user, $amount, $meta) {
            $target = User::where('id', $user->id)->lockForUpdate()->first();
            $target->balance = (int) $target->balance + $amount;
            $target->save();
            Payment::create([
                'user_id' => $target->id,
                'order_id' => 0,
                'method' => 'wordpress_wallet',
                'type' => 4,
                'price' => $amount,
                'status' => 1,
                'detail' => $meta,
                'expired_at' => now(),
            ]);
        });
        return (int) User::find($user->id)->balance;
    }

    public function debitWallet(User $user, int $amount, array $meta = []): array
    {
        $amount = max(0, $amount);
        return DB::transaction(function () use ($user, $amount, $meta) {
            $target = User::where('id', $user->id)->lockForUpdate()->first();
            if ((int) $target->balance < $amount) {
                return ['ok' => false, 'message' => 'موجودی کیف پول کافی نیست.', 'balance' => (int) $target->balance];
            }
            $target->balance = (int) $target->balance - $amount;
            $target->save();
            Payment::create([
                'user_id' => $target->id,
                'order_id' => 0,
                'method' => 'wordpress_wallet',
                'type' => 'wp_order',
                'price' => $amount,
                'status' => 1,
                'detail' => $meta,
                'expired_at' => now(),
            ]);
            return ['ok' => true, 'balance' => (int) $target->balance];
        });
    }
}
