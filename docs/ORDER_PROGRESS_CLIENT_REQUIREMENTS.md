# Order progress — client requirements (reference document)

This document captures the **three** improvements requested for the order production checklist / order status experience, in **plain language** for stakeholders, operations, and implementation planning.

---

## 1. Update date next to each action

### Goal

Customers should see **when** each production step was completed, not only whether it is completed, in progress, or pending.

### Why it needs product / engineering work

Today, progress is driven mainly by **tags** on the order. The shop shows **which** steps are done, but the standard order data does **not** include a reliable **“date and time this tag was added”** for each tag. To show a date per step, the business needs a agreed way to **record** those dates when steps advance.

### Intended outcome

- Each row in the production checklist can show a **completion date** (or equivalent) when that step is done.
- Dates appear consistently wherever the checklist is shown (new customer accounts and the classic storefront experience, once both are updated).

### Operational note

Older orders may **not** have historical dates until recording starts, or until tags change again after go-live. This should be set as a **customer expectation** if needed.

---

## 2. First step automatic — no “order created” tag

### Goal

The first step (for example “order received” / “order created”) should **not** require staff to add a dedicated tag. It should appear **automatically** as completed, with a **date** next to it.

### Intended outcome

- The **order creation date** (or another agreed shop date) is used as the date shown for that first step.
- Staff **do not** need to add a tag whose only meaning is “the order exists,” unless the business later decides to keep such a tag for internal reporting.

### Operational note

Confirm with the business **which row** in the checklist is always the “automatic” first step (for example the first line in the product checklist, or a clearly marked step in the configuration).

---

## 3. Split “notes” into two distinct parts

The client asked that what is today one mixed “notes” idea be **divided into two fields** with different roles.

### Part A — Predefined (how long the process will take)

- **Meaning:** The **normal** duration or estimate for that step under typical conditions (for example “about 3–5 business days”).
- **Where it is maintained:** On the **product**, in the **production checklist** metafield (same general area as today), with **clear fields** for standard timing / short explanatory text — not mixed with ad-hoc delay stories.
- **Who maintains it:** Whoever configures products and production stages.
- **What the customer sees:** **Per step**, the planned timing / estimate column behaves like a **standard schedule**, not a one-off excuse.

### Part B — Manual (why there is a delay on this order)

- **Meaning:** Free text when something is **not** normal: customer asked to pause, production error, change request, supplier delay, etc.
- **Where it is maintained:** On the **order**, not on the product. The store has an order metafield definition **`Production update`** (`custom.production_update`, multi-line text). Staff enter text on **that order’s** metafields screen when needed.
- **Who maintains it:** Staff, when a situation affects **this specific customer / order**.
- **What the customer sees:** Typically **one** prominent message for the **whole order** (not the same long paragraph repeated on every row), unless the business later chooses a more detailed per-step design.

### Summary table

| Part | Role | Where staff edit | Shown to customer |
|------|------|------------------|-------------------|
| A — Predefined | Standard time / estimate | Product → production checklist | Per step (timing column) |
| B — Manual | Delays / exceptions / story | Order → **Production update** | One order-level block (recommended) |

---

## Suggested delivery order

1. **Automatic first step + date** — fastest visible improvement; uses data already on the order.
2. **Split notes** — clarify product checklist for **Part A**; read **Production update** on the order for **Part B**; update all customer-facing checklist views.
3. **Dates for all other steps** — depends on recording tag completion times; plan training and expectations for historical orders.

---

## What the client / shop should confirm

- Which checklist row is always treated as the **automatic first step**.
- That **Production update** is the official place for **manual delay / exception** text (and whether the existing **Notes** order metafield is used for something else, to avoid confusion).
- That staff will continue to use **step tags** for all **non-automatic** steps, unless rules change.

---

## Related technical locations (for developers)

| Area | Path / note |
|------|-------------|
| Core checklist and tags logic | `app/Services/OrderProgressService.php` |
| Product checklist config | `config/order-progress.php` |
| New customer accounts UI | `extensions/customer-account-order-progress/src/index.jsx` |
| Classic storefront checklist | Theme asset `order-progress.js` (and CSS) |
| Order manual text (when implemented) | Order metafield `custom.production_update` — **Production update** |

Implementation of items 1–3 in code is **not** described in this document; it is tracked separately in engineering tasks.

---

*Document version: aligned with client three-point brief. Update this file if requirements or field names change.*
