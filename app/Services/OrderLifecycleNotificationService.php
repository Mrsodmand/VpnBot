<?php

namespace App\Services;

use App\Models\Orders;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class OrderLifecycleNotificationService
{
    public const RENEWAL_NOTICE_HOURS = 24;

    public const LOW_VOLUME_GB = 1.0;

    private const CLAIM_TIMEOUT_MINUTES = 5;

    private const ELIGIBLE_STATUSES = [
        Orders::STATUS_ACTIVE,
        Orders::STATUS_DATA_EXHAUSTED,
        Orders::STATUS_SUSPENDED,
        '1',
        '0',
        '2',
        'created',
        'limited',
        'expired',
        'on_hold',
    ];

    public function __construct(private readonly OrderLifecycleService $lifecycle)
    {
    }

    public function sendDueNotifications(?Carbon $now = null): array
    {
        $now ??= now();
        $this->lifecycle->reconcileTimeStatuses(now: $now);

        $renewEnabled = (int) Setting::where('key', 'renew')->value('value') === 1;
        $extraEnabled = (int) Setting::where('key', 'extra')->value('value') === 1;
        $result = [
            'checked' => 0,
            'renewal_sent' => 0,
            'volume_sent' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        if (!$renewEnabled && !$extraEnabled) {
            return $result;
        }

        Orders::query()
            ->with('user')
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use ($now, $renewEnabled, $extraEnabled, &$result) {
                foreach ($orders as $order) {
                    $result['checked']++;
                    $user = $order->user;

                    if (!$user || (string) $user->status !== '1' || empty($user->tel_id)) {
                        $result['skipped']++;
                        continue;
                    }

                    if ($renewEnabled && $this->renewalIsDue($order, $now)) {
                        $this->deliver(
                            $order,
                            'renewal_24h',
                            $this->renewalFingerprint($order),
                            $this->renewalMessage($order, $now),
                            $this->renewalKeyboard($order),
                            'renewal_sent',
                            $result
                        );
                    }

                    if ($extraEnabled && $this->volumeIsDue($order, $now)) {
                        $this->deliver(
                            $order,
                            'low_volume',
                            $this->volumeFingerprint($order),
                            $this->volumeMessage($order),
                            $this->volumeKeyboard($order),
                            'volume_sent',
                            $result
                        );
                    }
                }
            });

        return $result;
    }

    private function renewalIsDue(Orders $order, Carbon $now): bool
    {
        if (!$order->expire_at || !$this->lifecycle->canRenew($order, $now)) {
            return false;
        }

        $expireAt = Carbon::parse($order->expire_at);

        return $expireAt->gt($now)
            && $expireAt->lte($now->copy()->addHours(self::RENEWAL_NOTICE_HOURS));
    }

    private function volumeIsDue(Orders $order, Carbon $now): bool
    {
        if (!$this->lifecycle->canBuyExtra($order, $now)) {
            return false;
        }

        $lifecycle = $this->lifecycleDetail($order);
        $totalGb = $lifecycle['total_gb'] ?? null;
        $leftGb = $lifecycle['left_gb'] ?? null;
        $lastCheckedAt = $lifecycle['last_checked_at'] ?? null;

        if (!is_numeric($totalGb) || (float) $totalGb <= 0 || !is_numeric($leftGb) || !$lastCheckedAt) {
            return false;
        }

        try {
            $isFresh = Carbon::parse($lastCheckedAt)->gte($now->copy()->subMinutes(30));
        } catch (Throwable) {
            return false;
        }

        return $isFresh && (float) $leftGb <= self::LOW_VOLUME_GB;
    }

    private function deliver(
        Orders $order,
        string $type,
        string $fingerprint,
        string $message,
        array $keyboard,
        string $resultKey,
        array &$result
    ): void {
        $token = $this->claim($order, $type, $fingerprint);
        if ($token === null) {
            $result['skipped']++;
            return;
        }

        try {
            $response = $this->sendTelegramMessage([
                'chat_id' => $order->user->tel_id,
                'text' => $message,
                'parse_mode' => 'HTML',
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
            ]);
        } catch (Throwable $exception) {
            $response = ['ok' => false, 'error' => $exception->getMessage()];
        }

        if (!empty($response['ok'])) {
            $this->completeClaim($order->id, $type, $token);
            $result[$resultKey]++;
            return;
        }

        $this->releaseClaim($order->id, $type, $token);
        $result['failed']++;
        Log::warning('Order lifecycle notification failed', [
            'order_id' => $order->id,
            'type' => $type,
            'error' => $response['description'] ?? $response['error'] ?? 'unknown_error',
        ]);
    }

    private function claim(Orders $order, string $type, string $fingerprint): ?string
    {
        return DB::transaction(function () use ($order, $type, $fingerprint) {
            $lockedOrder = Orders::where('id', $order->id)->lockForUpdate()->first();
            if (!$lockedOrder) {
                return null;
            }

            $detail = is_array($lockedOrder->detail) ? $lockedOrder->detail : [];
            $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $notifications = is_array($lifecycle['notifications'] ?? null) ? $lifecycle['notifications'] : [];
            $current = is_array($notifications[$type] ?? null) ? $notifications[$type] : [];

            if (($current['fingerprint'] ?? null) === $fingerprint) {
                if (($current['state'] ?? null) === 'sent') {
                    return null;
                }

                if (($current['state'] ?? null) === 'sending' && $this->claimIsFresh($current)) {
                    return null;
                }
            }

            $token = (string) Str::uuid();
            $notifications[$type] = [
                'fingerprint' => $fingerprint,
                'state' => 'sending',
                'token' => $token,
                'started_at' => now()->toDateTimeString(),
            ];
            $lifecycle['notifications'] = $notifications;
            $detail['lifecycle'] = $lifecycle;
            $lockedOrder->detail = $detail;
            $lockedOrder->save();

            return $token;
        });
    }

    private function completeClaim(int $orderId, string $type, string $token): void
    {
        DB::transaction(function () use ($orderId, $type, $token) {
            $order = Orders::where('id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                return;
            }

            $detail = is_array($order->detail) ? $order->detail : [];
            $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $notifications = is_array($lifecycle['notifications'] ?? null) ? $lifecycle['notifications'] : [];
            $notification = is_array($notifications[$type] ?? null) ? $notifications[$type] : [];
            if (($notification['token'] ?? null) !== $token) {
                return;
            }

            $notification['state'] = 'sent';
            $notification['sent_at'] = now()->toDateTimeString();
            unset($notification['token'], $notification['started_at']);
            $notifications[$type] = $notification;
            $lifecycle['notifications'] = $notifications;
            $detail['lifecycle'] = $lifecycle;
            $order->detail = $detail;
            if ($type === 'renewal_24h') {
                $order->reminded = 1;
            }
            $order->save();
        });
    }

    private function releaseClaim(int $orderId, string $type, string $token): void
    {
        DB::transaction(function () use ($orderId, $type, $token) {
            $order = Orders::where('id', $orderId)->lockForUpdate()->first();
            if (!$order) {
                return;
            }

            $detail = is_array($order->detail) ? $order->detail : [];
            $lifecycle = is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
            $notifications = is_array($lifecycle['notifications'] ?? null) ? $lifecycle['notifications'] : [];
            $notification = is_array($notifications[$type] ?? null) ? $notifications[$type] : [];
            if (($notification['token'] ?? null) !== $token) {
                return;
            }

            unset($notifications[$type]);
            $lifecycle['notifications'] = $notifications;
            $detail['lifecycle'] = $lifecycle;
            $order->detail = $detail;
            $order->save();
        });
    }

    private function claimIsFresh(array $notification): bool
    {
        try {
            return Carbon::parse($notification['started_at'] ?? null)
                ->gte(now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES));
        } catch (Throwable) {
            return true;
        }
    }

    private function renewalFingerprint(Orders $order): string
    {
        return 'expire:' . Carbon::parse($order->expire_at)->copy()->utc()->format('Y-m-d\TH:i:s\Z');
    }

    private function volumeFingerprint(Orders $order): string
    {
        $lifecycle = $this->lifecycleDetail($order);
        $expireAt = $order->expire_at
            ? Carbon::parse($order->expire_at)->copy()->utc()->format('Y-m-d\TH:i:s\Z')
            : 'no-expiry';

        return $expireAt . '|total:' . number_format((float) $lifecycle['total_gb'], 3, '.', '');
    }

    private function renewalMessage(Orders $order, Carbon $now): string
    {
        $expireAt = Carbon::parse($order->expire_at);
        $minutes = max(1, (int) floor($now->diffInMinutes($expireAt, false)));
        $remaining = $minutes >= 60
            ? floor($minutes / 60) . ' ساعت و ' . ($minutes % 60) . ' دقیقه'
            : $minutes . ' دقیقه';

        return "⏰ <b>زمان تمدید سرویس نزدیک است</b>\n\n"
            . "کمتر از ۲۴ ساعت تا پایان سرویس شما باقی مانده است.\n\n"
            . "🔢 سفارش: <code>#{$order->id}</code>\n"
            . "🏷 نام سرویس: <code>{$this->escape($order->remark)}</code>\n"
            . "⏳ زمان باقی‌مانده: <b>{$remaining}</b>\n"
            . "📅 تاریخ انقضا: <code>{$expireAt->format('Y/m/d H:i')}</code>\n\n"
            . "برای جلوگیری از قطع سرویس، می‌توانید همین حالا آن را تمدید کنید.";
    }

    private function volumeMessage(Orders $order): string
    {
        $lifecycle = $this->lifecycleDetail($order);

        return "⚠️ <b>حجم سرویس رو به اتمام است</b>\n\n"
            . "حجم باقی‌مانده سرویس شما به ۱ گیگابایت یا کمتر رسیده است.\n\n"
            . "🔢 سفارش: <code>#{$order->id}</code>\n"
            . "🏷 نام سرویس: <code>{$this->escape($order->remark)}</code>\n"
            . "📦 حجم کل: <code>{$this->formatGb($lifecycle['total_gb'])}</code> گیگ\n"
            . "📊 حجم مصرف‌شده: <code>{$this->formatGb($lifecycle['used_gb'])}</code> گیگ\n"
            . "🟠 حجم باقی‌مانده: <b>{$this->formatGb($lifecycle['left_gb'])} گیگ</b>\n\n"
            . "برای جلوگیری از اتمام سرویس، می‌توانید حجم اضافه تهیه کنید.";
    }

    private function renewalKeyboard(Orders $order): array
    {
        return [
            [[
                'text' => '🔄 تمدید سرویس',
                'callback_data' => "type=clientRenewOrder|id={$order->id}",
            ]],
            [[
                'text' => '📄 مشاهده سفارش',
                'callback_data' => "type=clientSingleOrder|id={$order->id}",
            ]],
        ];
    }

    private function volumeKeyboard(Orders $order): array
    {
        return [
            [[
                'text' => '➕ خرید حجم',
                'callback_data' => "type=clientBuyExtra|id={$order->id}",
            ]],
            [[
                'text' => '📄 مشاهده سفارش',
                'callback_data' => "type=clientSingleOrder|id={$order->id}",
            ]],
        ];
    }

    private function lifecycleDetail(Orders $order): array
    {
        $detail = is_array($order->detail) ? $order->detail : [];

        return is_array($detail['lifecycle'] ?? null) ? $detail['lifecycle'] : [];
    }

    private function formatGb(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function sendTelegramMessage(array $params): array
    {
        return (new Telegram((string) env('TELEGRAM_BOT_TOKEN')))->sendMessage($params);
    }
}
