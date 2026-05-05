<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\OrderProgressService;
use App\Support\ShopifyWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ShopifyOrdersUpdatedController extends Controller
{
    public function __invoke(Request $request, OrderProgressService $orderProgress)
    {
        $secret = (string) config('shopify-app.api_secret', '');
        $raw = $request->getContent();

        if (! ShopifyWebhookVerifier::isValid($raw, $request->header('X-Shopify-Hmac-Sha256'), $secret)) {
            return response('Unauthorized', 401);
        }

        $shopDomain = (string) $request->header('X-Shopify-Shop-Domain', '');
        if ($shopDomain === '') {
            return response('Missing shop domain', 400);
        }

        $shop = User::query()->where('name', $shopDomain)->first();
        if ($shop === null) {
            return response('Shop not found', 404);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Invalid JSON', 400);
        }

        if (! Arr::has($payload, 'id')) {
            return response('OK', 200);
        }

        try {
            $orderProgress->recordStepCompletionsFromWebhook($shop, $payload);
        } catch (\Throwable $e) {
            report($e);

            return response('Server error', 500);
        }

        return response('OK', 200);
    }
}
