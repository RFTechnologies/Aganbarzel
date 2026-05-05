<?php

namespace App\Support;

class ShopifyWebhookVerifier
{
    public static function isValid(string $rawBody, ?string $hmacHeader, string $secret): bool
    {
        if ($hmacHeader === null || $hmacHeader === '' || $secret === '') {
            return false;
        }

        $computed = base64_encode(hash_hmac('sha256', $rawBody, $secret, true));

        return hash_equals($computed, $hmacHeader);
    }
}
