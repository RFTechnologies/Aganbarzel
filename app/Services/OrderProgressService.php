<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use RuntimeException;

class OrderProgressService
{
    public function build(User $shop, int $orderId, int $loggedInCustomerId): array
    {
        $order = $this->fetchOrder($shop, $orderId);

        if ($order === null) {
            throw new RuntimeException('Order not found');
        }

        $customerId = (int) Arr::get($order, 'customer.id', 0);
        if ($customerId !== $loggedInCustomerId) {
            throw new RuntimeException('Forbidden');
        }

        return $this->buildFromOrderArray($order);
    }

    /**
     * New-customer-accounts extensions often pass order GID. Convert safely.
     */
    public function normalizeOrderId(string $orderIdOrGid): int
    {
        $value = trim($orderIdOrGid);
        if ($value === '') {
            return 0;
        }
        if (ctype_digit($value)) {
            return (int) $value;
        }

        if (preg_match('/gid:\/\/shopify\/Order\/(\d+)/', $value, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    public function parseCustomerIdFromTokenSub(?string $sub): int
    {
        if ($sub === null || $sub === '') {
            return 0;
        }

        if (preg_match('/gid:\/\/shopify\/Customer\/(\d+)/', $sub, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    private function fetchOrder(User $shop, int $orderId): ?array
    {
        $version = config('shopify-app.api_version', '2022-04');
        $path = '/admin/api/'.$version.'/orders/'.$orderId.'.json';

        $response = $shop->api()->rest('GET', $path);

        if (! empty($response['errors'])) {
            return null;
        }

        $body = $response['body'] ?? null;
        if (is_object($body)) {
            $body = json_decode(json_encode($body), true);
        }
        if (! is_array($body)) {
            return null;
        }

        $order = $body['order'] ?? null;
        if (is_object($order)) {
            $order = json_decode(json_encode($order), true);
        }

        return is_array($order) ? $order : null;
    }

    private function buildFromOrderArray(array $order): array
    {
        $orderTags = $this->orderTagsFromShopify((string) Arr::get($order, 'tags', ''));

        return [
            'order_name' => (string) Arr::get($order, 'name', ''),
            'cancelled_at' => Arr::get($order, 'cancelled_at'),
            'order_tags' => $orderTags,
            'steps' => [],
            'eta_summary_he' => null,
            'payment_message_he' => null,
            'branch' => 'unknown',
            'financial_status' => (string) Arr::get($order, 'financial_status', ''),
            'fulfillment_status' => (string) Arr::get($order, 'fulfillment_status', ''),
            'is_payment_blocked' => false,
            'updated_at' => (string) Arr::get($order, 'updated_at', ''),
        ];
    }

    /**
     * Split Shopify Admin order tags (comma-separated). Preserves order,
     * trims whitespace, de-duplicates case-insensitively. No config.
     */
    private function orderTagsFromShopify(string $tagsCsv): array
    {
        $parts = explode(',', $tagsCsv);
        $seenLower = [];
        $out = [];

        foreach ($parts as $part) {
            $tag = trim($part);
            if ($tag === '') {
                continue;
            }

            $lower = mb_strtolower($tag, 'UTF-8');
            if (isset($seenLower[$lower])) {
                continue;
            }
            $seenLower[$lower] = true;
            $out[] = $tag;
        }

        return $out;
    }
}
