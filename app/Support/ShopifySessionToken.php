<?php

namespace App\Support;

class ShopifySessionToken
{
    public static function decodeAndVerify(?string $jwt): ?array
    {
        if ($jwt === null || $jwt === '') {
            return null;
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }

        [$headB64, $payloadB64, $sigB64] = $parts;
        $headerJson = self::base64UrlDecode($headB64);
        $payloadJson = self::base64UrlDecode($payloadB64);
        $signature = self::base64UrlDecode($sigB64);

        if ($headerJson === null || $payloadJson === null || $signature === null) {
            return null;
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);
        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $secret = (string) config('shopify-app.api_secret');
        if ($secret === '') {
            return null;
        }

        $expected = hash_hmac('sha256', $headB64.'.'.$payloadB64, $secret, true);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $now = time();
        $nbf = (int) ($payload['nbf'] ?? 0);
        $exp = (int) ($payload['exp'] ?? 0);
        if ($nbf > 0 && $now < $nbf) {
            return null;
        }
        if ($exp > 0 && $now >= $exp) {
            return null;
        }

        $aud = $payload['aud'] ?? null;
        $apiKey = (string) config('shopify-app.api_key');
        if ($apiKey !== '' && $aud !== $apiKey) {
            return null;
        }

        return $payload;
    }

    /**
     * Hostname from session token `dest` claim (customer account / App Bridge).
     * Shopify may send a full URL or a bare hostname; parse_url() needs a scheme to return PHP_URL_HOST.
     */
    public static function shopHostFromDest(?string $dest): ?string
    {
        if ($dest === null) {
            return null;
        }

        $dest = trim($dest);
        if ($dest === '') {
            return null;
        }

        if (str_contains($dest, '://') === false) {
            $dest = 'https://'.ltrim($dest, '/');
        }

        $host = parse_url($dest, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return strtolower($host);
    }

    private static function base64UrlDecode(string $input): ?string
    {
        $input = strtr($input, '-_', '+/');
        $pad = strlen($input) % 4;
        if ($pad > 0) {
            $input .= str_repeat('=', 4 - $pad);
        }

        $decoded = base64_decode($input, true);

        return $decoded === false ? null : $decoded;
    }
}
