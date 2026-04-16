<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OrderProgressService;
use App\Support\ShopifyAppProxy;
use Illuminate\Http\Request;
use RuntimeException;

class AppProxyOrderProgressController extends Controller
{
    public function show(Request $request, OrderProgressService $orderProgress)
    {
        if (! ShopifyAppProxy::isValid($request)) {
            return response()->json(['error' => 'Invalid or expired proxy signature'], 401);
        }

        $shopDomain = (string) $request->query('shop', '');
        if ($shopDomain === '') {
            return response()->json(['error' => 'Missing shop'], 400);
        }

        $loggedInCustomerId = $request->query('logged_in_customer_id');
        if ($loggedInCustomerId === null || $loggedInCustomerId === '') {
            return response()->json(['error' => 'Login required'], 401);
        }

        $orderId = (int) $request->query('order_id', 0);
        if ($orderId < 1) {
            return response()->json(['error' => 'order_id required'], 400);
        }

        $shop = User::where('name', $shopDomain)->first();
        if ($shop === null) {
            return response()->json(['error' => 'App not installed for this shop'], 404);
        }

        try {
            $payload = $orderProgress->build($shop, $orderId, (int) $loggedInCustomerId);
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
