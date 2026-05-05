# Order progress — detailed technical implementation plan

**Status:** Implemented in the codebase (migration, webhook, `OrderProgressService`, customer-account extension build, theme assets). Register the Shopify **Orders updated** webhook to `POST /webhooks/shopify/orders-updated` on your app URL and run `php artisan migrate` on each environment.

This document turns the **three client requirements** into an implementation blueprint with **file targets**, **data shapes**, and **example code**. Adapt namespaces and naming to match your conventions after review.

**Requirements covered**

1. **Update date next to each action** — persist first-seen completion time per step when order tags change.  
2. **No order-created tag** — first checklist step auto-complete with date from `order.created_at`.  
3. **Split notes** — (A) predefined estimate/duration per step from product checklist; (B) manual order-level delay text from order metafield `custom.production_update` (**Production update**).

---

## Prerequisites

| Item | Detail |
|------|--------|
| Shopify Admin | Order metafield **`Production update`** exists as `custom.production_update` (multi-line text). |
| API scopes | `read_orders`, `write_orders` if you write metafields from webhooks (optional); `read_products` already used for checklist. |
| Webhook HTTPS URL | Public URL for `POST /webhooks/shopify/orders-updated` (or similar). |

---

## High-level architecture

```text
Shopify orders/update webhook
        → Verify HMAC → Resolve shop User
        → Diff tags vs last snapshot → Match tags to checklist step keys
        → INSERT IGNORE / first-write-wins into order_progress_step_completions

GET order-progress (existing controllers)
        → fetchOrder + fetch order metafield production_update
        → merge checklist templates → buildStepsFromTemplate with auto-first-step + merged completion dates
        → JSON: steps[].estimate_display, steps[].completed_at, production_update_note
```

---

## Phase A — Configuration

Add to `config/order-progress.php` (keys are illustrative):

```php
/*
|--------------------------------------------------------------------------
| First step: auto-complete without order tag (uses order created_at as date)
|--------------------------------------------------------------------------
*/
'auto_complete_first_step' => true,

// Match mode: 'position' => lowest position row only; 'flag' => rows with auto_from_order true in JSON
'auto_complete_first_step_mode' => 'position',

/*
|--------------------------------------------------------------------------
| Order metafield: manual delay / production update (per order)
|--------------------------------------------------------------------------
*/
'order_production_update_namespace' => 'custom',
'order_production_update_key' => 'production_update',

/*
|--------------------------------------------------------------------------
| Tag completion timestamps (stored locally; Shopify does not expose per-tag times)
|--------------------------------------------------------------------------
*/
// No extra config required beyond webhook registration if using DB table below.
```

**Product checklist JSON** (extend gradually; backward compatible):

- Keep `note` as optional **legacy** copy mapped into estimate text if `estimate_note` is absent.  
- Add **`estimate_note`** (string, optional): predefined human-readable duration text only.  
- Add **`auto_from_order`** (boolean, optional): if `auto_complete_first_step_mode` is `flag`, rows with `true` skip tag requirement.

Example row:

```json
{
  "key": "s1",
  "label_he": "ההזמנה התקבלה",
  "tag": "step-order-received",
  "position": 1,
  "eta_days": 0,
  "estimate_note": "Order entered production queue same day.",
  "auto_from_order": true
}
```

---

## Phase B — Database (tag completion dates)

Create a migration, e.g. `database/migrations/xxxx_xx_xx_create_order_progress_step_completions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_progress_step_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // shops_users / users — adjust to your FK
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('step_key', 191);
            $table->timestampTz('completed_at');
            $table->timestamps();

            $table->unique(['user_id', 'shopify_order_id', 'step_key'], 'opsc_order_step_unique');
            $table->index(['user_id', 'shopify_order_id'], 'opsc_order_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_progress_step_completions');
    }
};
```

**Optional:** Table `order_progress_tag_snapshots` (`user_id`, `shopify_order_id`, `tags_normalized_csv`, `updated_at`) to diff tags between webhooks. Alternatively, load previous completions only and infer “new tag” by recomparing checklist tags against stored keys — simpler but cannot detect tag **removal**; usually acceptable if policy is “first completion wins.”

**Eloquent model** `App\Models\OrderProgressStepCompletion` with `$fillable = ['user_id', 'shopify_order_id', 'step_key', 'completed_at'];`.

---

## Phase C — Webhook: record completion times when tags change

### C1 Register webhook

Either:

- **Partner Dashboard:** Create subscription **Orders updated** → `https://YOUR_APP_DOMAIN/webhooks/shopify/orders-updated`, API version aligned with app;  
- Or **`config/shopify-app.php`** `webhooks` entry + reinstall flow per `kyon147/laravel-shopify` docs.

### C2 Verify Shopify HMAC

Shopify sends `X-Shopify-Hmac-Sha256`. Verify against **raw request body** using `SHOPIFY_API_SECRET` (same secret as app). Reference: [Verify webhook](https://shopify.dev/docs/apps/build/webhooks/subscribe/https).

Example helper `App\Support\ShopifyWebhookVerifier.php`:

```php
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
```

### C3 Controller skeleton

`App\Http\Controllers\Webhooks\ShopifyOrdersUpdatedController.php`:

```php
<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\OrderProgressStepCompletion;
use App\Models\User;
use App\Services\OrderProgressService;
use App\Support\ShopifyWebhookVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class ShopifyOrdersUpdatedController extends Controller
{
    public function __invoke(Request $request, OrderProgressService $orderProgress)
    {
        $secret = config('shopify-app.api_secret');
        $raw = $request->getContent();
        if (! ShopifyWebhookVerifier::isValid($raw, $request->header('X-Shopify-Hmac-Sha256'), (string) $secret)) {
            return response('Unauthorized', 401);
        }

        $shopDomain = $request->header('X-Shopify-Shop-Domain');
        $shop = User::where('name', $shopDomain)->first();
        if ($shop === null) {
            return response('Shop not found', 404);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response('Bad payload', 400);
        }

        $orderId = (int) ($payload['id'] ?? 0);
        if ($orderId < 1) {
            return response('OK', 200);
        }

        // Build normalized tags from webhook payload (comma-separated string on REST order)
        $tagsCsv = (string) ($payload['tags'] ?? '');
        $tagsNormalized = $orderProgress->normalizeTagsLowerPublic($tagsCsv); // expose existing private method as public wrapper

        // Resolve merged checklist template keys/tags for this order (reuse service: fetch order + product IDs + merge templates)
        // Pseudocode: $templates = $orderProgress->mergedTemplatesForShopOrder($shop, $payload);
        foreach ($templates as $row) {
            $stepKey = (string) ($row['key'] ?? '');
            $tag = mb_strtolower(trim((string) ($row['tag'] ?? '')), 'UTF-8');
            if ($stepKey === '' || $tag === '') {
                continue;
            }
            if (! in_array($tag, $tagsNormalized, true)) {
                continue;
            }
            // Skip auto-first-step rows if you do not want DB rows for them (date comes from order created_at in API only)
            // Or insert same timestamp as created_at once — simpler to skip in webhook.

            OrderProgressStepCompletion::firstOrCreate(
                [
                    'user_id' => $shop->id,
                    'shopify_order_id' => $orderId,
                    'step_key' => $stepKey,
                ],
                ['completed_at' => Carbon::now('UTC')]
            );
        }

        return response('OK', 200);
    }
}
```

**Important:** Extract **`mergedTemplatesForShopOrder`** logic into a **public** method on `OrderProgressService` so webhook and `build()` share one implementation (avoid drift).

Route `routes/web.php` (exclude CSRF for this URI in `VerifyCsrfToken` `$except`):

```php
Route::post('/webhooks/shopify/orders-updated', \App\Http\Controllers\Webhooks\ShopifyOrdersUpdatedController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
```

**Queue:** Move webhook body handling to a queued job to respond quickly (`202` optional).

---

## Phase D — `OrderProgressService` changes

### D1 Auto-complete first step + date from order

After building `$steps` from tags (or inside `buildStepsFromTemplate`), apply a dedicated pass:

```php
private function applyAutoFirstStep(array $steps, array $order, array $templateRows): array
{
    if (! config('order-progress.auto_complete_first_step', false) || $steps === []) {
        return $steps;
    }

    $mode = config('order-progress.auto_complete_first_step_mode', 'position');
    $createdAt = Arr::get($order, 'created_at');
    $iso = $this->normalizeShopifyDatetimeToIso8601($createdAt); // implement: Carbon::parse(...)->toIso8601String()

    $targetIndex = null;
    if ($mode === 'position') {
        $minPos = PHP_INT_MAX;
        foreach ($templateRows as $i => $row) {
            $p = (int) ($row['position'] ?? $i);
            if ($p < $minPos) {
                $minPos = $p;
                $targetIndex = array_search($row['key'], array_column($steps, 'key'), true);
            }
        }
        // Alternatively find minimum position among templateRows then match step key in $steps
    }
    // flag mode: foreach steps/template align by key where auto_from_order === true

    if ($targetIndex !== null && isset($steps[$targetIndex])) {
        $steps[$targetIndex]['done'] = true;
        $steps[$targetIndex]['completed_at'] = $iso;
        $steps[$targetIndex]['auto_completed'] = true;
    }

    return $this->applyFirstPendingAsInProgress($steps); // re-run state machine after forcing done
}
```

**Careful:** Recomputing `$allDone` and `$pendingEtaDays` must happen **after** auto-first-step so summaries stay correct.

### D2 Merge stored completion timestamps

Load completions:

```php
$dates = OrderProgressStepCompletion::query()
    ->where('user_id', $shop->id)
    ->where('shopify_order_id', (int) Arr::get($order, 'id'))
    ->pluck('completed_at', 'step_key');
```

For each step with `done === true` and empty `completed_at`, set from `$dates[$stepKey]` formatted ISO. Auto-first-step keeps `order.created_at`.

### D3 Fetch order metafield `production_update`

```php
private function fetchOrderProductionUpdateNote(User $shop, int $orderId): ?string
{
    $ns = trim((string) config('order-progress.order_production_update_namespace', 'custom'));
    $key = trim((string) config('order-progress.order_production_update_key', 'production_update'));
    $version = config('shopify-app.api_version', '2022-04');
    $path = '/admin/api/'.$version.'/orders/'.$orderId.'/metafields.json';

    $response = $shop->api()->rest('GET', $path, ['query' => ['limit' => 250]]);
    // loop metafields where namespace/key match (same pattern as fetchProductChecklistTemplates)

    return $trimmedOrNull;
}
```

Add to `buildFromOrderArray` return payload:

```php
'production_update_note' => $productionUpdateNote, // nullable string at root for order-level manual notes
```

### D4 Split predefined vs legacy note in steps

Extend `parseChecklistJson`:

```php
'estimate_note' => isset($row['estimate_note']) ? (string) $row['estimate_note'] : null,
// keep 'note' for backward compatibility — treat as estimate_note fallback
```

In `buildStepsFromTemplate`, build:

```php
$estimateNote = $row['estimate_note'] ?? $noteStr; // predefined text only
$steps[] = [
    // ...
    'estimate_note' => $estimateNote,
    'estimate_display' => $this->composeEstimateDisplay($estimateNote, $etaForResponse),
    // optionally omit merging manual note into notes_display — manual only at root
    'notes_display' => $this->composeEstimateDisplay($estimateNote, $etaForResponse),
    'completed_at' => null, // filled in merge phase
];
```

### D5 Response shape (per step)

Each element of `steps` should include:

| Field | Type | Meaning |
|-------|------|---------|
| `done` | bool | Including auto-first-step |
| `step_state` | string | `done` / `in_progress` / `pending` |
| `estimate_display` | string \| null | Predefined ETA + estimate note only |
| `completed_at` | string \| null | ISO 8601 when step marked done (tag DB or auto `created_at`) |
| `note` | string \| null | Legacy; can mirror estimate note |

Root payload:

| Field | Type |
|-------|------|
| `production_update_note` | string \| null |

---

## Phase E — Customer account UI extension

File: `extensions/customer-account-order-progress/src/index.jsx`

1. **Optional banner block** below main status banner:

```jsx
{payload?.production_update_note?.trim() ? (
  <Banner status="warning" title="עדכון מהיצור">
    <Text>{payload.production_update_note}</Text>
  </Banner>
) : null}
```

2. **Grid columns:** widen to four columns or nest date under stage:

**Option A — Four columns:** `Production stage | Status | Completed | Notes / estimates`

**Option B — Date under stage:**

```jsx
<Text size="small" emphasis="bold">{label}</Text>
{step.completed_at ? (
  <Text size="extraSmall" appearance="subdued">{formatDate(step.completed_at)}</Text>
) : null}
```

Use `Intl.DateTimeFormat` or a small helper for locale (`he-IL`).

3. **Notes column:** render **`estimate_display`** (or fallback to current `notes_display`) — must **not** duplicate `production_update_note` per row.

Rebuild extension: `npm run build` / `shopify app build` per your workflow; deploy extension.

---

## Phase F — Theme (`order-progress.js` + CSS)

File: `anagn theme file/assets/order-progress.js` (and duplicate in repo if you version theme assets).

1. After fulfillment message, if `data.production_update_note`:

```javascript
html +=
  '<div class="order-progress-production-update" role="region">' +
  '<strong class="order-progress-production-update__title">Production update</strong>' +
  '<p class="order-progress-production-update__body">' +
  escapeHtml(data.production_update_note) +
  '</p></div>';
```

2. Table header: add **`Completed`** column or append date in stage cell:

```javascript
html +=
  '<th scope="col">Completed</th><th scope="col">Notes / estimates</th>';
// ...
html +=
  '<td class="order-progress-completed">' +
  (step.completed_at ? escapeHtml(formatIsoDate(step.completed_at)) : '') +
  '</td>';
```

3. Body notes column: use `step.estimate_display || step.notes_display`.

4. Style in `order-production-update` / column widths in `order-progress.css`.

---

## Phase G — Testing checklist

| Case | Expected |
|------|----------|
| New order, checklist with auto first row | Step 1 done, `completed_at` ≈ order `created_at`, no tag required |
| Add tag `step-mirror-ordered` | Webhook fires → DB row → API shows date on that step |
| Fill Production update on order | API returns `production_update_note`; banner visible both UIs |
| Product JSON only `note` | Maps to estimate column (backward compatible) |
| Multi-product order | Merged keys; completions keyed by `step.key` |

---

## Phase H — Operations / rollout

1. Run migrations on staging/production.  
2. Register webhook; verify delivery in Shopify **Settings → Notifications → Webhooks** (or Partner app webhook list).  
3. Add CSRF exception + logging/monitoring on webhook failures.  
4. Train staff: **Production update** on order for delays; stop redundant **order-created** tag if policy applies.  
5. Optionally backfill: script that reads current orders + tags and inserts `firstOrCreate` completions with `Carbon::now()` (imprecise) — usually skipped.

---

## File checklist

| Area | Action |
|------|--------|
| `config/order-progress.php` | New keys |
| `database/migrations/` | New table |
| `app/Models/OrderProgressStepCompletion.php` | New |
| `app/Services/OrderProgressService.php` | Auto step, dates merge, order metafield, estimate split, public helper for webhook |
| `app/Http/Controllers/Webhooks/ShopifyOrdersUpdatedController.php` | New |
| `app/Support/ShopifyWebhookVerifier.php` | New |
| `routes/web.php` | POST webhook route + CSRF except |
| `bootstrap/app.php` or `VerifyCsrfToken` | Exclude webhook URI |
| `extensions/customer-account-order-progress/src/index.jsx` | Dates + manual note banner |
| Theme `order-progress.js` / `.css` | Same |
| `docs/ORDER_PROGRESS_CLIENT_REQUIREMENTS.md` | Keep in sync with shipped behavior |

---

## Risks / decisions

- **Webhook latency:** Dates appear shortly after tag save; acceptable for most merchants.  
- **Historical orders:** No dates until webhook runs post-deploy — communicate clearly.  
- **Race:** Rare duplicate webhook deliveries — `firstOrCreate` handles idempotency.  
- **Timezone:** Store UTC in DB; format in UI with shop/customer locale.

---

*This plan is a blueprint; align FK on `user_id` with your actual `users` / shops table and run `php artisan migrate` only after review.*
