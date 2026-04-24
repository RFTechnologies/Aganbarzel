# Order progress: product metafield checklist + order tags

Implementation plan for the client model:

- **Product metafield** = step template (per product type).
- **Order tags** = which steps are complete.
- **Order status UI** = merged checklist for that order (customer account + app proxy theme).

**Out of scope for this plan:** true tag “timeline history” with per-event timestamps (requires webhooks + persistent storage; separate project).

---

## 1) Contract (before coding)

### 1.1 Shopify metafield

- In **Settings → Metafields and metaobjects → Products**, open the **Production checklist** (or equivalent) definition.
- Copy **Namespace and key** (e.g. `custom.production_checklist`) — this will go in `config/order-progress.php`.
- **Type:** JSON.

### 1.2 JSON shape (per product)

Single array of steps. Each object should include:

| Field | Required | Meaning |
|--------|----------|--------|
| `key` | Yes | Stable id; used to de-duplicate when merging line items. |
| `label` or `label_he` | Yes | Shown in the customer checklist. |
| `tag` | Yes | **Exact order tag** that means this step is done (matched case-insensitively in code). |
| `eta_days` | No | For ETA / notes behavior (optional). |
| `note` | No | Free text, e.g. “Estimated 3–5 business days”. |
| `position` | No | Sort order when merging products (optional). |

**Staff rule:** the value in `tag` must be a real tag that staff add on the order when that step completes.

**Example:**

```json
[
  { "key": "order-received", "label": "Order received", "tag": "step-order-received", "eta_days": 0 },
  { "key": "mirror-ordered", "label": "Mirror ordered", "tag": "step-mirror-ordered" }
]
```

### 1.3 Multi-product orders

- Load metafield JSON for each **unique** `product_id` in the order’s line items.
- Merge all steps: **de-duplicate by `key`** (define rule: first wins, or use `position`).
- Sort by `position` if present, else stable merge order.
- **Done** for each step = the order’s current tags include that step’s `tag`.

---

## 2) Backend (Laravel)

### 2.1 `config/order-progress.php`

Add (use real values from Admin):

- `product_checklist_metafield_namespace` — e.g. `custom`
- `product_checklist_metafield_key` — e.g. `production_checklist`
- `use_product_metafield_checklist` — `true` to enable
- `fallback_steps_when_no_metafield` — `true` to keep using global `steps` if a product has no/invalid JSON

Keep existing:

- `payment_blocking_financial_statuses`, `payment_message_he`
- `pickup_tag`, `delivery_tag`, `fulfillment_message_*_he` (for payment and fulfillment messaging).

### 2.2 `app/Services/OrderProgressService.php`

**Current:** `build()` → `fetchOrder()` → `buildFromOrderArray()` using `config('order-progress.steps')` + order tags.

**Target:**

1. After loading the order, read `line_items` and collect **unique** `product_id` values.
2. For each product, fetch the product metafield (REST metafields endpoint, or product resource including metafields — pick one and keep consistent).
3. Parse JSON; on invalid/empty, apply fallback to global `steps` if configured.
4. **Merge** steps from all products (de-dupe by `key`).
5. For each step, set `done` from order tag list; compute optional ETAs; detect when **all** merged steps are done.
6. Set `fulfillment_message_he` / `branch` using existing rules, but “production complete” should use **merged** checklist (when `use_product_metafield_checklist` is on), not only the old global `steps`.
7. Return payload including:
   - `steps` — merged checklist (same key the UI already expects), **or** add `checklist_source` to distinguish `product_metafield` vs `config_fallback`
   - `order_tags` — still useful for debugging / optional raw section
   - Existing: `cancelled_at`, `is_payment_blocked`, `payment_message_he`, `financial_status`, etc.

**Performance:** N requests for N products — acceptable at first; can cache per product in a later iteration.

**Scopes:** Ensure the app has **`read_products`** in Partner Dashboard so metafields can be read.

### 2.3 Controllers

- `app/Http/Controllers/Storefront/CustomerAccountOrderProgressController.php` — no signature change; still returns `OrderProgressService::build()`.
- `app/Http/Controllers/Storefront/AppProxyOrderProgressController.php` — same JSON for theme.

### 2.4 Tests (recommended)

- Mock `User::api()->rest`: order with line items, two products, two metafield payloads, merged `steps` and correct `done` flags.

---

## 3) Customer account extension

### File: `extensions/customer-account-order-progress/src/index.jsx`

- Render the **checklist** from `payload.steps` (label + done + optional note/eta) as the main block.
- Keep banner priority aligned with product rules, e.g.:
  1. Cancelled
  2. Payment blocked
  3. `fulfillment-delivery` / `fulfillment-pickup` (from order tags)
  4. Else in production
- Optional: collapsible “Raw order tags” using `order_tags`.
- Set `API_BASE_URL` to production when deploying live.

### Deploy

- `shopify app deploy` after build.

---

## 4) Classic theme (app proxy)

### File: `assets/order-progress.js` (theme) or equivalent in theme repo

- Same API response: render `steps` as a table/rows (not only tag bullets).
- URL unchanged: existing proxy path + `order_id` + logged-in customer.

---

## 5) End-to-end flow (after implementation)

1. Admin fills **Production checklist** JSON on each product (per product type).
2. Customer places an order; line items point at those products.
3. Laravel loads order → products → metafields → merge → compares **order tags** to each step’s `tag`.
4. Customer order status shows ✅ / ⏳ per row.
5. Admin adds the matching order tag; refresh → row completes.
6. Multi-product: one merged list; duplicate `key` only once.

---

## 6) Suggested implementation order

1. Confirm metafield namespace + key; paste sample JSON on two test products.
2. Implement product metafield fetch + merge in `OrderProgressService`.
3. Point “all steps done” + fulfillment to merged steps when feature flag is on.
4. Update `index.jsx` (and theme JS) to render `steps` as the main checklist.
5. Staging: single product, two products, payment blocked, delivery/pickup tags.
6. Production deploy + `php artisan config:cache` (or `config:clear` as needed).

---

## 7) Related files (this repo)

| Area | File |
|------|------|
| Config | `config/order-progress.php` |
| API payload | `app/Services/OrderProgressService.php` |
| Customer account API | `app/Http/Controllers/Storefront/CustomerAccountOrderProgressController.php` |
| App proxy API | `app/Http/Controllers/Storefront/AppProxyOrderProgressController.php` |
| Routes | `routes/api.php`, `routes/web.php` |
| Customer account UI | `extensions/customer-account-order-progress/src/index.jsx` |
| Theme (optional) | `order-progress.js` in theme + snippet that embeds it |

---

## 8) Not in this plan

- **Tag history / timeline** with “newest first, never remove old” and real timestamps: requires appending events (webhook + DB or metafield JSON log). Track as a follow-up if the client still wants it after v1.
