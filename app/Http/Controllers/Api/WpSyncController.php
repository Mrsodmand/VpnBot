<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WpSyncService;
use Illuminate\Http\Request;

class WpSyncController extends Controller
{
    private function guard(Request $request, WpSyncService $sync)
    {
        if (!$sync->authorize($request->header('X-IPSABET-API-KEY'))) {
            return response()->json(['ok' => false, 'message' => 'unauthorized'], 403);
        }
        return null;
    }

    public function ping(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        return response()->json(['ok' => true, 'message' => 'wp-sync-ready']);
    }

    public function walletBalance(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->linkedUserByTelId($request->input('tel_id')) ?: $sync->linkedUserByPhone($request->input('phone'));
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'user_not_linked'], 404);
        }
        return response()->json(['ok' => true, 'balance' => (int) $user->balance, 'tel_id' => (string) $user->tel_id]);
    }

    public function walletCredit(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->linkedUserByTelId($request->input('tel_id')) ?: $sync->linkedUserByPhone($request->input('phone'));
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'user_not_linked'], 404);
        }
        $amount = (int) $request->input('amount', 0);
        $balance = $sync->creditWallet($user, $amount, $request->all());
        return response()->json(['ok' => true, 'balance' => $balance]);
    }

    public function walletDebit(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->linkedUserByTelId($request->input('tel_id')) ?: $sync->linkedUserByPhone($request->input('phone'));
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'user_not_linked'], 404);
        }
        $result = $sync->debitWallet($user, (int) $request->input('amount', 0), $request->all());
        return response()->json($result, !empty($result['ok']) ? 200 : 422);
    }

    public function ordersList(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $user = $sync->linkedUserByTelId($request->input('tel_id')) ?: $sync->linkedUserByPhone($request->input('phone'));
        if (!$user) {
            return response()->json(['ok' => false, 'message' => 'user_not_linked'], 404);
        }
        $orders = $user->orders()->orderByDesc('id')->limit(100)->get()->map(fn ($order) => $sync->formatOrder($order))->values();
        return response()->json(['ok' => true, 'orders' => $orders]);
    }

    public function importSiteOrder(Request $request, WpSyncService $sync)
    {
        if ($g = $this->guard($request, $sync)) return $g;
        $order = $request->input('order', []);
        if (!is_array($order)) {
            return response()->json(['ok' => false, 'message' => 'invalid_order_payload'], 422);
        }
        $saved = $sync->importSiteOrder($order);
        if (!$saved) {
            return response()->json(['ok' => false, 'message' => 'linked_user_not_found_or_invalid_order'], 404);
        }
        return response()->json(['ok' => true, 'bot_order_id' => $saved->id]);
    }
}
