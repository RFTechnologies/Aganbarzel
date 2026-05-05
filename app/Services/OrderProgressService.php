<?php

namespace App\Services;

use App\Models\OrderProgressStepCompletion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
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

        return $this->buildFromOrderArray($shop, $order);
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

    /**
     * @return list<int>
     */
    private function uniqueProductIdsFromOrder(array $order): array
    {
        $lineItems = Arr::get($order, 'line_items', []);
        if (! is_array($lineItems)) {
            return [];
        }

        $ids = [];
        $seen = [];
        foreach ($lineItems as $line) {
            if (! is_array($line)) {
                continue;
            }
            $pid = (int) ($line['product_id'] ?? 0);
            if ($pid < 1) {
                continue;
            }
            if (isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            $ids[] = $pid;
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configStepsAsTemplates(array $stepsConfig): array
    {
        $out = [];
        $i = 0;
        foreach ($stepsConfig as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = (string) ($row['label_he'] ?? $row['label'] ?? '');
            $out[] = [
                'key' => (string) ($row['key'] ?? ''),
                'label_he' => $label,
                'label' => $label,
                'tag' => (string) ($row['tag'] ?? ''),
                'eta_days' => (int) ($row['eta_days'] ?? 0),
                'note' => isset($row['note']) ? (string) $row['note'] : null,
                'estimate_note' => isset($row['estimate_note']) ? (string) $row['estimate_note'] : null,
                'auto_from_order' => filter_var($row['auto_from_order'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'position' => (int) ($row['position'] ?? $i),
            ];
            $i++;
        }

        return $out;
    }

    /**
     * Parse metafield JSON into a list of step templates.
     *
     * @return list<array<string, mixed>>
     */
    private function parseChecklistJson(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (! is_array($decoded)) {
            return [];
        }

        if (Arr::isAssoc($decoded)) {
            $decoded = [$decoded];
        }

        $out = [];
        $i = 0;
        foreach ($decoded as $row) {
            if (! is_array($row)) {
                continue;
            }
            $key = trim((string) ($row['key'] ?? ''));
            if ($key === '') {
                continue;
            }
            $label = (string) ($row['label_he'] ?? $row['label'] ?? '');
            $out[] = [
                'key' => $key,
                'label_he' => $label,
                'label' => $label,
                'tag' => (string) ($row['tag'] ?? ''),
                'eta_days' => (int) ($row['eta_days'] ?? 0),
                'note' => isset($row['note']) ? (string) $row['note'] : null,
                'estimate_note' => isset($row['estimate_note']) ? (string) $row['estimate_note'] : null,
                'auto_from_order' => filter_var($row['auto_from_order'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'position' => (int) ($row['position'] ?? $i),
            ];
            $i++;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetchProductChecklistTemplates(User $shop, int $productId): ?array
    {
        $version = config('shopify-app.api_version', '2022-04');
        $namespace = trim((string) config('order-progress.product_checklist_metafield_namespace', 'custom'));
        $key = trim((string) config('order-progress.product_checklist_metafield_key', 'production_checklist'));
        if ($namespace === '' || $key === '') {
            return null;
        }

        $path = '/admin/api/'.$version.'/products/'.$productId.'/metafields.json';
        $response = $shop->api()->rest('GET', $path, ['query' => ['limit' => 250]]);

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

        $metafields = $body['metafields'] ?? null;
        if (! is_array($metafields)) {
            return null;
        }

        $nsLower = mb_strtolower($namespace, 'UTF-8');
        $keyLower = mb_strtolower($key, 'UTF-8');

        foreach ($metafields as $mf) {
            if (! is_array($mf)) {
                continue;
            }
            $mfNs = mb_strtolower((string) ($mf['namespace'] ?? ''), 'UTF-8');
            $mfKey = mb_strtolower((string) ($mf['key'] ?? ''), 'UTF-8');
            if ($mfNs !== $nsLower || $mfKey !== $keyLower) {
                continue;
            }

            $val = (string) ($mf['value'] ?? '');

            return $this->parseChecklistJson($val);
        }

        return null;
    }

    /**
     * Merge templates from multiple products; first occurrence of `key` wins.
     *
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    private function mergeChecklistFromProducts(User $shop, array $productIds): array
    {
        $merged = [];
        $seenKeys = [];

        foreach ($productIds as $productId) {
            $templates = $this->fetchProductChecklistTemplates($shop, $productId);
            if ($templates === null || $templates === []) {
                continue;
            }

            foreach ($templates as $row) {
                $k = (string) ($row['key'] ?? '');
                if ($k === '' || isset($seenKeys[$k])) {
                    continue;
                }
                $seenKeys[$k] = true;
                $merged[] = $row;
            }
        }

        usort($merged, function (array $a, array $b) {
            $pa = (int) ($a['position'] ?? 0);
            $pb = (int) ($b['position'] ?? 0);
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp((string) ($a['key'] ?? ''), (string) ($b['key'] ?? ''));
        });

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $templateRows
     * @return array{0: list<array<string, mixed>>, 1: bool, 2: int}
     */
    private function buildStepsFromTemplate(
        array $templateRows,
        array $tagsNormalized,
        bool $paymentBlocked,
        ?string $autoFirstStepKey,
        ?string $orderCreatedAtIso
    ): array {
        $steps = [];
        $pendingEtaDays = 0;
        $allDone = count($templateRows) > 0;

        foreach ($templateRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rowKey = trim((string) ($row['key'] ?? ''));
            $tag = mb_strtolower(trim((string) ($row['tag'] ?? '')), 'UTF-8');
            $isAutoFirst = $autoFirstStepKey !== null && $rowKey !== '' && $rowKey === $autoFirstStepKey;
            $done = $isAutoFirst || ($tag !== '' && in_array($tag, $tagsNormalized, true));
            if (! $done) {
                $allDone = false;
            }
            $eta = (int) ($row['eta_days'] ?? 0);
            if (! $done && ! $paymentBlocked) {
                $pendingEtaDays += $eta;
            }
            $label = (string) ($row['label_he'] ?? $row['label'] ?? '');
            $legacyNote = $row['note'] ?? null;
            $legacyNoteStr = is_string($legacyNote) && $legacyNote !== '' ? $legacyNote : null;
            $estimateExplicit = $row['estimate_note'] ?? null;
            $estimateExplicitStr = is_string($estimateExplicit) && trim($estimateExplicit) !== ''
                ? trim($estimateExplicit)
                : null;
            $estimateNoteStr = $estimateExplicitStr ?? $legacyNoteStr;

            $etaForResponse = $done ? null : ($paymentBlocked ? null : $eta);
            $estimateDisplay = $this->composeEstimateDisplay($estimateNoteStr, $etaForResponse);
            $completedAt = ($isAutoFirst && $orderCreatedAtIso !== null && $orderCreatedAtIso !== '')
                ? $orderCreatedAtIso
                : null;

            $steps[] = [
                'key' => $rowKey,
                'label_he' => $label,
                'label' => $label,
                'tag' => (string) ($row['tag'] ?? ''),
                'note' => $estimateNoteStr,
                'estimate_note' => $estimateNoteStr,
                'done' => $done,
                'eta_days' => $etaForResponse,
                'estimate_display' => $estimateDisplay,
                'notes_display' => $estimateDisplay,
                'completed_at' => $completedAt,
            ];
        }

        if ($templateRows === []) {
            $allDone = false;
        }

        $steps = $this->applyFirstPendingAsInProgress($steps);

        return [$steps, $allDone, $pendingEtaDays];
    }

    /**
     * Predefined estimate column: template text plus optional ETA line.
     */
    private function composeEstimateDisplay(?string $estimateNoteStr, $etaForResponse): ?string
    {
        $etaLine = null;
        if (is_int($etaForResponse) && $etaForResponse > 0) {
            $etaLine = 'הערכה: כ-'.$etaForResponse.' ימים (הערכה בלבד)';
        }

        if ($estimateNoteStr !== null && $etaLine !== null) {
            return $estimateNoteStr.' · '.$etaLine;
        }
        if ($estimateNoteStr !== null) {
            return $estimateNoteStr;
        }

        return $etaLine;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function applyFirstPendingAsInProgress(array $steps): array
    {
        $assigned = false;
        foreach ($steps as $i => $step) {
            if (! is_array($step)) {
                continue;
            }
            if (! empty($step['done'])) {
                $steps[$i]['step_state'] = 'done';
                continue;
            }
            if (! $assigned) {
                $steps[$i]['step_state'] = 'in_progress';
                $assigned = true;
            } else {
                $steps[$i]['step_state'] = 'pending';
            }
        }

        return $steps;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, source: string}
     */
    private function resolveMergedTemplateRows(User $shop, array $order): array
    {
        $stepsConfig = config('order-progress.steps', []);
        if (! is_array($stepsConfig)) {
            $stepsConfig = [];
        }

        $useMeta = (bool) config('order-progress.use_product_metafield_checklist', false);
        $fallback = (bool) config('order-progress.fallback_steps_when_no_metafield', true);

        $templateRows = [];
        $checklistSource = 'config';

        if ($useMeta) {
            $productIds = $this->uniqueProductIdsFromOrder($order);
            $templateRows = $this->mergeChecklistFromProducts($shop, $productIds);
            if ($templateRows !== []) {
                $checklistSource = 'product_metafield';
            } elseif ($fallback) {
                $templateRows = $this->configStepsAsTemplates($stepsConfig);
                $checklistSource = 'config_fallback';
            } else {
                $templateRows = [];
                $checklistSource = 'empty';
            }
        } else {
            $templateRows = $this->configStepsAsTemplates($stepsConfig);
        }

        return ['rows' => $templateRows, 'source' => $checklistSource];
    }

    /**
     * @param  list<array<string, mixed>>  $templateRows
     */
    private function resolveAutoFirstStepKey(array $templateRows): ?string
    {
        if (! (bool) config('order-progress.auto_complete_first_step', false)) {
            return null;
        }

        if ($templateRows === []) {
            return null;
        }

        $mode = (string) config('order-progress.auto_complete_first_step_mode', 'position');

        if ($mode === 'flag') {
            $bestKey = null;
            $bestPos = PHP_INT_MAX;
            foreach ($templateRows as $row) {
                if (! is_array($row) || empty($row['auto_from_order'])) {
                    continue;
                }
                $k = trim((string) ($row['key'] ?? ''));
                if ($k === '') {
                    continue;
                }
                $p = (int) ($row['position'] ?? PHP_INT_MAX);
                if ($p < $bestPos) {
                    $bestPos = $p;
                    $bestKey = $k;
                }
            }

            return $bestKey;
        }

        $bestKey = null;
        $minPos = PHP_INT_MAX;
        foreach ($templateRows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $k = trim((string) ($row['key'] ?? ''));
            if ($k === '') {
                continue;
            }
            $p = (int) ($row['position'] ?? $i);
            if ($p < $minPos) {
                $minPos = $p;
                $bestKey = $k;
            }
        }

        return $bestKey;
    }

    private function normalizeShopifyDatetimeToIso8601($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc()->toIso8601String();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @return list<array<string, mixed>>
     */
    private function mergeCompletionDatesFromDatabase(User $shop, int $shopifyOrderId, array $steps): array
    {
        if ($shopifyOrderId < 1 || $steps === []) {
            return $steps;
        }

        $map = OrderProgressStepCompletion::query()
            ->where('user_id', $shop->id)
            ->where('shopify_order_id', $shopifyOrderId)
            ->pluck('completed_at', 'step_key');

        foreach ($steps as $i => $step) {
            if (! is_array($step) || empty($step['done'])) {
                continue;
            }
            if (! empty($step['completed_at'])) {
                continue;
            }
            $key = (string) ($step['key'] ?? '');
            if ($key === '' || ! isset($map[$key])) {
                continue;
            }
            $steps[$i]['completed_at'] = Carbon::parse($map[$key])->utc()->toIso8601String();
        }

        return $steps;
    }

    private function fetchOrderProductionUpdateNote(User $shop, int $orderId): ?string
    {
        $namespace = trim((string) config('order-progress.order_production_update_namespace', 'custom'));
        $key = trim((string) config('order-progress.order_production_update_key', 'production_update'));
        if ($namespace === '' || $key === '' || $orderId < 1) {
            return null;
        }

        $version = config('shopify-app.api_version', '2022-04');
        $path = '/admin/api/'.$version.'/orders/'.$orderId.'/metafields.json';
        $response = $shop->api()->rest('GET', $path, ['query' => ['limit' => 250]]);

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

        $metafields = $body['metafields'] ?? null;
        if (! is_array($metafields)) {
            return null;
        }

        $nsLower = mb_strtolower($namespace, 'UTF-8');
        $keyLower = mb_strtolower($key, 'UTF-8');

        foreach ($metafields as $mf) {
            if (! is_array($mf)) {
                continue;
            }
            $mfNs = mb_strtolower((string) ($mf['namespace'] ?? ''), 'UTF-8');
            $mfKey = mb_strtolower((string) ($mf['key'] ?? ''), 'UTF-8');
            if ($mfNs !== $nsLower || $mfKey !== $keyLower) {
                continue;
            }
            $val = trim((string) ($mf['value'] ?? ''));

            return $val !== '' ? $val : null;
        }

        return null;
    }

    /**
     * Merged checklist templates for an order payload (products + fallback). Used by webhooks and API.
     *
     * @return list<array<string, mixed>>
     */
    public function mergedChecklistTemplatesForOrder(User $shop, array $order): array
    {
        return $this->resolveMergedTemplateRows($shop, $order)['rows'];
    }

    /**
     * Persist first-seen completion timestamps when order tags include a step tag (excludes auto-first step).
     */
    public function recordStepCompletionsFromWebhook(User $shop, array $orderPayload): void
    {
        $orderId = (int) Arr::get($orderPayload, 'id');
        if ($orderId < 1) {
            return;
        }

        $templates = $this->mergedChecklistTemplatesForOrder($shop, $orderPayload);
        if ($templates === []) {
            return;
        }

        $tagsNormalized = $this->normalizeTagsLower((string) Arr::get($orderPayload, 'tags', ''));
        $autoKey = $this->resolveAutoFirstStepKey($templates);
        $now = Carbon::now('UTC');

        foreach ($templates as $row) {
            if (! is_array($row)) {
                continue;
            }
            $stepKey = trim((string) ($row['key'] ?? ''));
            if ($stepKey === '') {
                continue;
            }
            if ($autoKey !== null && $stepKey === $autoKey) {
                continue;
            }
            $tag = mb_strtolower(trim((string) ($row['tag'] ?? '')), 'UTF-8');
            if ($tag === '' || ! in_array($tag, $tagsNormalized, true)) {
                continue;
            }

            OrderProgressStepCompletion::query()->firstOrCreate(
                [
                    'user_id' => $shop->id,
                    'shopify_order_id' => $orderId,
                    'step_key' => $stepKey,
                ],
                ['completed_at' => $now]
            );
        }
    }

    private function buildFromOrderArray(User $shop, array $order): array
    {
        $tagsNormalized = $this->normalizeTagsLower((string) Arr::get($order, 'tags', ''));
        $financialStatus = (string) Arr::get($order, 'financial_status', '');
        $fulfillmentStatus = (string) Arr::get($order, 'fulfillment_status', '');
        $paymentBlocked = $this->isPaymentBlocked($financialStatus);

        $resolved = $this->resolveMergedTemplateRows($shop, $order);
        $templateRows = $resolved['rows'];
        $checklistSource = $resolved['source'];

        $autoFirstKey = $this->resolveAutoFirstStepKey($templateRows);
        $orderCreatedIso = $this->normalizeShopifyDatetimeToIso8601(Arr::get($order, 'created_at'));

        [$steps, $allStepsDone, $pendingEtaDays] = $this->buildStepsFromTemplate(
            $templateRows,
            $tagsNormalized,
            $paymentBlocked,
            $autoFirstKey,
            $orderCreatedIso
        );

        $shopifyOrderId = (int) Arr::get($order, 'id');
        $steps = $this->mergeCompletionDatesFromDatabase($shop, $shopifyOrderId, $steps);
        $productionUpdateNote = $this->fetchOrderProductionUpdateNote($shop, $shopifyOrderId);

        $pickupTag = mb_strtolower(trim((string) config('order-progress.pickup_tag', '')), 'UTF-8');
        $deliveryTag = mb_strtolower(trim((string) config('order-progress.delivery_tag', '')), 'UTF-8');

        $branch = 'unknown';
        if ($pickupTag !== '' && in_array($pickupTag, $tagsNormalized, true)) {
            $branch = 'pickup';
        } elseif ($deliveryTag !== '' && in_array($deliveryTag, $tagsNormalized, true)) {
            $branch = 'delivery';
        }

        $cancelledAt = Arr::get($order, 'cancelled_at');
        $isCancelled = $cancelledAt !== null && $cancelledAt !== '';

        $fulfillmentMessageHe = null;
        if ($allStepsDone && ! $paymentBlocked && ! $isCancelled) {
            if ($branch === 'pickup') {
                $msg = trim((string) config('order-progress.fulfillment_message_pickup_he', ''));
                $fulfillmentMessageHe = $msg !== '' ? $msg : null;
            } elseif ($branch === 'delivery') {
                $msg = trim((string) config('order-progress.fulfillment_message_delivery_he', ''));
                $fulfillmentMessageHe = $msg !== '' ? $msg : null;
            }
        }

        $paymentMessageHe = $paymentBlocked
            ? trim((string) config('order-progress.payment_message_he', ''))
            : null;
        if ($paymentMessageHe === '') {
            $paymentMessageHe = $paymentBlocked
                ? 'קיימת יתרת תשלום. שחרור המוצר או המשלוח יתאפשרו לאחר סגירת החשבון במלואו.'
                : null;
        }

        $orderTagsDisplay = $this->orderTagsFromShopify((string) Arr::get($order, 'tags', ''));

        return [
            'order_name' => (string) Arr::get($order, 'name', ''),
            'financial_status' => $financialStatus,
            'fulfillment_status' => $fulfillmentStatus,
            'cancelled_at' => $cancelledAt,
            'branch' => $branch,
            'is_payment_blocked' => $paymentBlocked,
            'payment_message_he' => $paymentMessageHe,
            'steps' => $steps,
            'eta_summary_he' => $this->formatEtaSummaryHe($pendingEtaDays, $paymentBlocked),
            'fulfillment_message_he' => $fulfillmentMessageHe,
            'updated_at' => (string) Arr::get($order, 'updated_at', ''),
            'order_tags' => $orderTagsDisplay,
            'checklist_source' => $checklistSource,
            'production_update_note' => $productionUpdateNote,
        ];
    }

    /**
     * Lowercase tags for case-insensitive matching against config step tags.
     *
     * @return list<string>
     */
    private function normalizeTagsLower(string $tagsCsv): array
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

    /**
     * Display tags: preserve Admin casing, order, de-dupe case-insensitively.
     *
     * @return list<string>
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

    private function isPaymentBlocked(string $financialStatus): bool
    {
        $blocked = config('order-progress.payment_blocking_financial_statuses', []);
        if (! is_array($blocked)) {
            return false;
        }

        $status = mb_strtolower($financialStatus, 'UTF-8');
        foreach ($blocked as $value) {
            if (mb_strtolower((string) $value, 'UTF-8') === $status) {
                return true;
            }
        }

        return false;
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
