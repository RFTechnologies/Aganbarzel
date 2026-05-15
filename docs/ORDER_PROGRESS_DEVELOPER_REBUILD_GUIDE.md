# Order Progress — Developer Rebuild Guide (with code)

Use this document to **rebuild the same feature from scratch** in a Laravel + Shopify app, plus theme + Customer Account extension.

**Canonical source repo:** `C:\Users\adx\Documents\GitHub\Aganbarzel`  
**Theme repo:** `C:\Users\adx\Desktop\anagn theme file`

**Prerequisites**

- Laravel app with `kyon147/laravel-shopify` (or equivalent) — shop stored in `users.name` = `*.myshopify.com`
- Shopify Partner app with scopes: `read_products`, `read_orders`, `write_orders` (minimum for this feature)
- PHP 8.x, MySQL, Composer
- Node.js for extension build
- Shopify CLI (`shopify app build`, `shopify app deploy`)

---

## Build order (checklist)

| Step | What to create |
|------|----------------|
| 1 | Migration + model |
| 2 | `config/order-progress.php` |
| 3 | Support classes (HMAC, session token, app proxy) |
| 4 | `OrderProgressService.php` (copy full file from repo) |
| 5 | Three controllers + routes + CSRF exception |
| 6 | `shopify.app.toml` webhook |
| 7 | Customer Account UI extension |
| 8 | Theme snippet + JS + CSS |
| 9 | Partner Dashboard: app proxy + webhook |
| 10 | Shopify Admin metafield definitions |
| 11 | Migrate + deploy + test |

---

## Step 1 — Database migration

**Create:** `database/migrations/2026_05_01_120000_create_order_progress_step_completions_table.php`

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
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
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

Run: `php artisan migrate`

---

## Step 2 — Eloquent model

**Create:** `app/Models/OrderProgressStepCompletion.php`

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderProgressStepCompletion extends Model
{
    protected $fillable = [
        'user_id',
        'shopify_order_id',
        'step_key',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

---

## Step 3 — Config

**Create:** `config/order-progress.php`

Copy the **entire file** from the repository (`config/order-progress.php`). It defines:

- Fallback `steps[]` (Hebrew labels + `tag` + `eta_days`)
- `payment_blocking_financial_statuses`, `payment_message_he`
- `pickup_tag`, `delivery_tag`, fulfillment Hebrew messages
- `use_product_metafield_checklist`, `product_checklist_metafield_namespace` / `_key`
- `auto_complete_first_step`, `auto_complete_first_step_mode`
- `order_production_update_namespace` / `_key`

**Optional `.env`:**

```env
SHOPIFY_CUSTOMER_ACCOUNT_SHOP_DOMAIN=your-store.myshopify.com
```

Wire in `config/shopify-app.php`:

```php
'customer_account_order_progress_shop_domain' => env('SHOPIFY_CUSTOMER_ACCOUNT_SHOP_DOMAIN', ''),
```

---

## Step 4 — Support classes (auth)

### 4.1 Webhook HMAC

**Create:** `app/Support/ShopifyWebhookVerifier.php`

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

### 4.2 App Proxy HMAC

**Create:** `app/Support/ShopifyAppProxy.php`

```php
<?php

namespace App\Support;

use Illuminate\Http\Request;

class ShopifyAppProxy
{
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
```

### 4.3 Customer Account session JWT

**Create:** `app/Support/ShopifySessionToken.php`

Copy **entire file** from `app/Support/ShopifySessionToken.php` in the repo (~140 lines). It:

- Verifies HS256 JWT with `SHOPIFY_API_SECRET`
- Checks `nbf` / `exp` / `aud` = API key
- Resolves shop host from `dest` or `iss` claims

---

## Step 5 — OrderProgressService (core logic)

**Create:** `app/Services/OrderProgressService.php`

This file is **~800 lines**. **Copy it verbatim** from the repository:

```
app/Services/OrderProgressService.php
```

### Public API (what other classes call)

```php
// Build JSON for storefront (both endpoints)
public function build(User $shop, int $orderId, int $loggedInCustomerId): array

// Webhook: save first-seen completion per step tag
public function recordStepCompletionsFromWebhook(User $shop, array $orderPayload): void

// Shared checklist merge for webhook + API
public function mergedChecklistTemplatesForOrder(User $shop, array $order): array

// Customer Account helpers
public function normalizeOrderId(string $orderIdOrGid): int
public function parseCustomerIdFromTokenSub(?string $sub): int
```

### Algorithm summary (implement in service)

1. **`build()`** — `fetchOrder()` via Admin REST → verify `customer.id` === logged-in customer → `buildFromOrderArray()`.
2. **`resolveMergedTemplateRows()`** — product IDs from line items → load `custom.production_checklist` metafields → merge by `key`, sort by `position` → fallback to `config('order-progress.steps')`.
3. **`resolveAutoFirstStepKey()`** — lowest `position` or `auto_from_order` flag.
4. **`buildStepsFromTemplate()`** — for each row: `done` if auto-first OR tag on order; `estimate_display` from `estimate_note` + ETA; auto-first gets `completed_at` = order `created_at`.
5. **`mergeCompletionDatesFromDatabase()`** — fill `completed_at` from `order_progress_step_completions` for done steps without date.
6. **`fetchOrderProductionUpdateNote()`** — read order metafield `custom.production_update`.
7. **Payment / pickup / delivery / cancelled** — set banners and `eta_summary_he`.
8. **`recordStepCompletionsFromWebhook()`** — foreach template step (skip auto-first): if tag present → `firstOrCreate` with `completed_at = now()` UTC.

---

## Step 6 — Controllers

### 6.1 Webhook

**Create:** `app/Http/Controllers/Webhooks/ShopifyOrdersUpdatedController.php`

```php
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
```

### 6.2 App Proxy (classic theme)

**Create:** `app/Http/Controllers/Storefront/AppProxyOrderProgressController.php`

```php
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
```

### 6.3 Customer Account API

**Create:** `app/Http/Controllers/Storefront/CustomerAccountOrderProgressController.php`

```php
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
```

---

## Step 7 — Routes & CSRF

**In `routes/api.php`:**

```php
use App\Http\Controllers\Storefront\CustomerAccountOrderProgressController;

Route::get('/customer-account/order-progress', [CustomerAccountOrderProgressController::class, 'show'])
    ->name('storefront.customer-account.order-progress');
```

**In `routes/web.php`:**

```php
use App\Http\Controllers\Storefront\AppProxyOrderProgressController;
use App\Http\Controllers\Webhooks\ShopifyOrdersUpdatedController;

Route::get('/proxy/order-progress', [AppProxyOrderProgressController::class, 'show'])
    ->name('storefront.app-proxy.order-progress');

Route::post('/webhooks/shopify/orders-updated', ShopifyOrdersUpdatedController::class)
    ->name('webhooks.shopify.orders-updated');
```

**In `app/Http/Middleware/VerifyCsrfToken.php`**, add to `$except`:

```php
'webhooks/shopify/orders-updated',
```

---

## Step 8 — shopify.app.toml

**At repo root:**

```toml
client_id = "YOUR_SHOPIFY_API_KEY"
name = "rf-price-calculator"
application_url = "https://YOUR_LARAVEL_DOMAIN"
embedded = true

[access_scopes]
scopes = "read_products,write_products,write_customers,write_draft_orders,read_orders,write_orders,read_shipping,write_shipping"

[auth]
redirect_urls = [
  "https://YOUR_LIVE_DOMAIN/authenticate",
  "https://YOUR_LIVE_DOMAIN/auth/callback",
]

[webhooks]
api_version = "2022-04"

[[webhooks.subscriptions]]
topics = [ "orders/updated" ]
uri = "/webhooks/shopify/orders-updated"
```

Deploy: `shopify app deploy` (after `application_url` matches environment).

---

## Step 9 — Customer Account UI extension

### 9.1 Folder structure

```
extensions/customer-account-order-progress/
├── shopify.extension.toml
├── package.json
├── src/index.jsx
└── dist/                    # generated by build
```

### 9.2 `shopify.extension.toml`

```toml
api_version = "2025-07"

[[extensions]]
name = "Customer Order Progress"
handle = "customer-account-order-progress"
type = "ui_extension"

[[extensions.targeting]]
target = "customer-account.order-status.block.render"
module = "./src/index.jsx"

[extensions.capabilities]
api_access = true
network_access = true
```

### 9.3 `package.json`

```json
{
  "name": "customer-account-order-progress",
  "private": true,
  "version": "1.0.0",
  "dependencies": {
    "@shopify/ui-extensions": "2025.7.4",
    "@shopify/ui-extensions-react": "2025.7.4",
    "react": "^18.3.1",
    "react-dom": "^18.3.1",
    "react-reconciler": "^0.29.2"
  }
}
```

### 9.4 `src/index.jsx`

Copy **entire file** from:

```
extensions/customer-account-order-progress/src/index.jsx
```

**Critical parts to implement:**

```javascript
export default reactExtension(
  "customer-account.order-status.block.render",
  () => <OrderProgressBlock />,
);

const API_BASE_URL = "https://YOUR_LARAVEL_DOMAIN"; // NO trailing slash

// Inside useEffect:
const token = await api.sessionToken.get();
const url =
  API_BASE_URL +
  "/api/customer-account/order-progress?order_id=" +
  encodeURIComponent(orderId);

const res = await fetch(url, {
  method: "GET",
  headers: {
    Authorization: `Bearer ${token}`,
    Accept: "application/json",
  },
});
```

**UI must render:**

- Status banner from `getPrimaryStatus(payload, tags)`
- `production_update_note` warning banner
- Grid: Production stage | Status | Completed | Notes/estimates
- `formatCompletedAt(step.completed_at)` for Completed column
- `estimateColumnText(step)` preferring `estimate_display`

**Build & deploy:**

```bash
cd extensions/customer-account-order-progress
npm install
shopify app build
shopify app deploy
```

---

## Step 10 — Classic theme (anagn theme file)

### 10.1 Snippet

**Create:** `snippets/order-progress.liquid`

```liquid
{% comment %}
  App proxy: Partner Dashboard → App proxy
  Subpath: rf-price-calculator
  Proxy URL: https://YOUR_LARAVEL_DOMAIN/proxy/order-progress
{% endcomment %}

{% if customer %}
  {% assign order_progress_apps_subpath = 'rf-price-calculator' %}

  <div
    id="order-progress-root"
    class="order-progress-host"
    data-order-id="{{ order.id }}"
    data-proxy-base="{{ shop.url }}/apps/{{ order_progress_apps_subpath }}/order-progress"
  ></div>

  <style>
    .order-progress-host { margin: 1.5rem 0; }
    .order-progress-card { border: 1px solid #e0e0e0; border-radius: 4px; padding: 1.25rem; background: #fafafa; }
    .order-progress-error { color: #c00; padding: 1rem 0; }
  </style>

  <script src="{{ 'order-progress.js' | asset_url }}" defer></script>
{% endif %}
```

### 10.2 Order template

**Edit:** `templates/customers/order.liquid` — add near top of order content:

```liquid
{% render 'order-progress' %}
```

### 10.3 JavaScript

**Create:** `assets/order-progress.js`

Copy **entire file** from theme repo (`assets/order-progress.js`, ~245 lines). Core logic:

```javascript
var url = base + '?order_id=' + encodeURIComponent(orderId);
fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
  .then(/* render table from data.steps, data.production_update_note */);
```

### 10.4 CSS

**Create:** `assets/order-progress.css`

Copy **entire file** from theme repo (`assets/order-progress.css`, ~227 lines).

Backup copy in Laravel repo: `theme-assets-anagn/order-progress.css`.

---

## Step 11 — Partner Dashboard configuration

### App proxy

| Field | Value |
|-------|--------|
| Subpath prefix | `rf-price-calculator` (must match `shopify.app.toml` `name`) |
| Proxy URL | `https://YOUR_LARAVEL_DOMAIN/proxy/order-progress` |

### Webhook

Either via `shopify app deploy` or manually:

- Topic: **Order update** / `orders/updated`
- URL: `https://YOUR_LARAVEL_DOMAIN/webhooks/shopify/orders-updated`
- Format: JSON

---

## Step 12 — Shopify Admin metafields

### Product — production checklist

- **Namespace:** `custom`
- **Key:** `production_checklist`
- **Type:** JSON (array)

Example value:

```json
[
  {
    "key": "order_received",
    "label_he": "ההזמנה התקבלה",
    "tag": "stage-order-received",
    "position": 0,
    "eta_days": 0,
    "estimate_note": "יום קליטה"
  },
  {
    "key": "suppliers_ordered",
    "label_he": "הוזמנו חלקים",
    "tag": "stage-suppliers-ordered",
    "position": 1,
    "eta_days": 3,
    "estimate_note": "כ-3 ימי עסקים"
  }
]
```

### Order — production update

- **Namespace:** `custom`
- **Key:** `production_update`
- **Type:** Multi-line text

---

## API response contract (both endpoints)

Implement this JSON shape in `buildFromOrderArray()`:

```json
{
  "order_name": "#1001",
  "financial_status": "paid",
  "fulfillment_status": null,
  "cancelled_at": null,
  "branch": "unknown",
  "is_payment_blocked": false,
  "payment_message_he": null,
  "steps": [
    {
      "key": "suppliers_ordered",
      "label_he": "...",
      "tag": "stage-suppliers-ordered",
      "done": true,
      "step_state": "done",
      "completed_at": "2026-05-01T10:00:00+00:00",
      "estimate_display": "...",
      "eta_days": null
    }
  ],
  "eta_summary_he": "...",
  "fulfillment_message_he": null,
  "order_tags": ["tag1"],
  "checklist_source": "product_metafield",
  "production_update_note": "Optional delay text",
  "updated_at": "..."
}
```

---

## Files to copy verbatim from repository

| File | Lines (approx) | Action |
|------|----------------|--------|
| `app/Services/OrderProgressService.php` | ~800 | Copy entire file |
| `app/Support/ShopifySessionToken.php` | ~140 | Copy entire file |
| `extensions/.../src/index.jsx` | ~417 | Copy entire file |
| `config/order-progress.php` | ~137 | Copy entire file |
| `assets/order-progress.js` (theme) | ~245 | Copy entire file |
| `assets/order-progress.css` (theme) | ~227 | Copy entire file |
| `snippets/order-progress.liquid` | ~31 | Copy entire file |

All other files are included in full in this guide above.

---

## Verification tests

1. **API (Customer Account):** `GET /api/customer-account/order-progress?order_id=GID` with valid Bearer token → 200 + JSON.
2. **App Proxy:** Logged-in customer on `/account/orders/:id` → widget loads, no 401.
3. **Webhook:** Add stage tag on order → row in `order_progress_step_completions` → `completed_at` on next page load.
4. **Auto-first:** First step done without tag; date ≈ order created.
5. **Production update:** Order metafield text → `production_update_note` in UI.
6. **Security:** Wrong customer → 403; invalid proxy signature → 401.

---

## Related documentation

| Doc | Purpose |
|-----|---------|
| `ORDER_PROGRESS_DEVELOPMENT_GUIDE.md` | Architecture & operations |
| `ORDER_PROGRESS_CLIENT_REQUIREMENTS.md` | Business requirements |
| `ORDER_PROGRESS_IMPLEMENTATION_PLAN.md` | Original technical plan |
| `extensions/customer-account-order-progress/README.md` | Extension deploy notes |

---

*End of developer rebuild guide.*
