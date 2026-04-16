<?php

namespace App\Support;

use Illuminate\Http\Request;

class ShopifyAppProxy
{
    /**
     * Verify Shopify App Proxy HMAC (Admin API secret key).
     *
     * @see https://shopify.dev/docs/apps/build/online-store/app-proxies/authenticate-app-proxies
     */
    public static function isValid(Request $request): bool
    {
        $signature = $request->query('signature');
        $secret = (string) config('shopify-app.api_secret');

        if ($signature === null || $signature === '' || $secret === '') {
            return false;
        }

        $timestamp = $request->query('timestamp');
        if ($timestamp !== null && $timestamp !== '') {
            $ts = (int) $timestamp;
            if ($ts > 0 && abs(time() - $ts) > 300) {
                return false;
            }
        }

        $params = $request->query();
        unset($params['signature']);

        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $value = implode(',', $value);
            }
            $pairs[] = $key.'='.$value;
        }
        sort($pairs, SORT_STRING);
        $message = implode('', $pairs);

        $digest = hash_hmac('sha256', $message, $secret, false);

        return hash_equals($digest, $signature);
    }
}
