<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'status',
        'user_id',
        'order_id',
        'admin_id',
        'method',
        'ref_id',
        'type',
        'price',
        'status',
        'detail',
        'expired_at',
    ];

    protected function casts()
    {
        return [
            'detail' => 'array',
            'expired_at' => 'datetime',
        ];
    }

    public function scopeForOrderHistory(Builder $query, Orders $order): Builder
    {
        $detail = is_array($order->detail)
            ? $order->detail
            : (json_decode((string) $order->detail, true) ?: []);
        $preOrderId = (int) ($detail['preOrderId'] ?? $detail['pre_order_id'] ?? $detail['pre-order-id'] ?? 0);

        return $query
            ->where('user_id', $order->user_id)
            ->where(function (Builder $history) use ($order, $preOrderId) {
                $history->where(function (Builder $direct) use ($order) {
                    $direct->where('order_id', $order->id)
                        ->whereIn('type', ['2', '3', 'renew', 'extra', 'wp_order']);
                });

                if ($preOrderId > 0) {
                    $history->orWhere(function (Builder $purchase) use ($preOrderId) {
                        $purchase->where('order_id', $preOrderId)
                            ->whereIn('type', ['1', 'order']);
                    });
                } else {
                    $history->orWhere(function (Builder $legacyPurchase) use ($order) {
                        $legacyPurchase->where('order_id', $order->id)
                            ->whereIn('type', ['1', 'order']);
                    });
                }
            });
    }

    public function scopeForWalletHistory(Builder $query, User $user): Builder
    {
        return $query
            ->where('user_id', $user->id)
            ->where(function (Builder $history) {
                $history->whereIn('method', [
                    'wallet',
                    'wordpress_wallet',
                    'admin_credit',
                    'admin_debit',
                ])->orWhereIn('type', [
                    '4',
                    'wallet',
                    'wallet_charge',
                    'admin_credit',
                    'admin_debit',
                ])->orWhere('status', -2);
            });
    }
}
