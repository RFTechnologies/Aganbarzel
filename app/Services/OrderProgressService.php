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

    private function normalizeTags(string $tagsCsv): array
    {
        $parts = array_map('trim', explode(',', $tagsCsv));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            $out[] = mb_strtolower($p, 'UTF-8');
        }

        return $out;
    }

    private function buildFromOrderArray(array $order): array
    {
        $tags = $this->normalizeTags((string) Arr::get($order, 'tags', ''));
        $financialStatus = (string) Arr::get($order, 'financial_status', '');
        $fulfillmentStatus = (string) Arr::get($order, 'fulfillment_status', '');

        $paymentBlocked = $this->isPaymentBlocked($financialStatus);

        $stepsConfig = config('order-progress.steps', []);
        $steps = [];
        $pendingEtaDays = 0;

        foreach ($stepsConfig as $row) {
            $tag = strtolower(trim((string) ($row['tag'] ?? '')));
            $done = $tag !== '' && in_array($tag, $tags, true);
            $eta = (int) ($row['eta_days'] ?? 0);

            if (! $done && ! $paymentBlocked) {
                $pendingEtaDays += $eta;
            }

            $steps[] = [
                'key' => (string) ($row['key'] ?? ''),
                'label_he' => (string) ($row['label_he'] ?? ''),
                'done' => $done,
                'eta_days' => $done ? null : ($paymentBlocked ? null : $eta),
            ];
        }

        $pickupTag = strtolower((string) config('order-progress.pickup_tag', ''));
        $deliveryTag = strtolower((string) config('order-progress.delivery_tag', ''));

        $branch = 'unknown';
        if ($pickupTag !== '' && in_array($pickupTag, $tags, true)) {
            $branch = 'pickup';
        } elseif ($deliveryTag !== '' && in_array($deliveryTag, $tags, true)) {
            $branch = 'delivery';
        }

        $etaSummaryHe = $this->formatEtaSummaryHe($pendingEtaDays, $paymentBlocked);

        return [
            'order_name' => (string) Arr::get($order, 'name', ''),
            'financial_status' => $financialStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'cancelled_at' => Arr::get($order, 'cancelled_at'),
            'branch' => $branch,
            'is_payment_blocked' => $paymentBlocked,
            'payment_message_he' => $paymentBlocked
                ? 'קיימת יתרת תשלום. שחרור המוצר או המשלוח יתאפשרו לאחר סגירת החשבון במלואו.'
                : null,
            'steps' => $steps,
            'eta_summary_he' => $etaSummaryHe,
            'updated_at' => (string) Arr::get($order, 'updated_at', ''),
        ];
    }

    private function isPaymentBlocked(string $financialStatus): bool
    {
        $blocked = config('order-progress.payment_blocking_financial_statuses', []);

        return in_array(mb_strtolower($financialStatus, 'UTF-8'), $blocked, true);
    }

    private function formatEtaSummaryHe(int $pendingEtaDays, bool $paymentBlocked): ?string
    {
        if ($paymentBlocked) {
            return 'ההמשך תלוי בביצוע תשלום מלא.';
        }
        if ($pendingEtaDays <= 0) {
            return 'כל השלבים המוגדרים הושלמו או אין הערכת זמן נותרת.';
        }

        return 'הערכת זמן משוערת לשלבים שנותרו: כ-'.$pendingEtaDays.' ימים (הערכה בלבד).';
    }
}
