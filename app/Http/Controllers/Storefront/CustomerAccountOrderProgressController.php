<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OrderProgressService;
use App\Support\ShopifySessionToken;
use Illuminate\Http\Request;
use RuntimeException;

class CustomerAccountOrderProgressController extends Controller
{
    public function show(Request $request, OrderProgressService $orderProgress)
    {
        $bearer = $request->bearerToken();
        $claims = ShopifySessionToken::decodeAndVerify($bearer);
        if ($claims === null) {
            return response()->json(['error' => 'Invalid session token'], 401);
        }

        $shopDomain = ShopifySessionToken::shopHostFromSessionClaims($claims);
        if ($shopDomain === null || $shopDomain === '') {
            return response()->json(['error' => 'Invalid token destination'], 401);
        }

        $shop = User::where('name', $shopDomain)->first();
        $fallbackName = config('shopify-app.customer_account_order_progress_shop_domain');
        if ($shop === null && is_string($fallbackName) && $fallbackName !== '') {
            $shop = User::where('name', $fallbackName)->first();
        }
        if ($shop === null) {
            return response()->json(['error' => 'App not installed for this shop'], 404);
        }

        $orderId = $orderProgress->normalizeOrderId((string) $request->query('order_id', ''));
        if ($orderId < 1) {
            return response()->json(['error' => 'order_id is required'], 400);
        }

        $customerId = $orderProgress->parseCustomerIdFromTokenSub((string) ($claims['sub'] ?? ''));
        if ($customerId < 1) {
            return response()->json(['error' => 'Customer context missing in token'], 401);
        }

        try {
            $payload = $orderProgress->build($shop, $orderId, $customerId);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'Forbidden') {
                return response()->json(['error' => 'Forbidden'], 403);
            }
            if ($e->getMessage() === 'Order not found') {
                return response()->json(['error' => 'Order not found'], 404);
            }

            return response()->json(['error' => 'Unable to load order'], 500);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'Server error'], 500);
        }

        return response()->json($payload);
    }
}
