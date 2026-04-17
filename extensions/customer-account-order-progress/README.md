# Customer Account Order Progress Extension

This extension renders the order-progress checklist on **new customer accounts** order pages.

## What it calls

- Backend endpoint: `GET /api/customer-account/order-progress?order_id=...`
- Auth: Shopify customer **session token** (Bearer JWT)

## Before deploy

1. Ensure backend changes are deployed (`CustomerAccountOrderProgressController`, `ShopifySessionToken`).
2. Confirm `SHOPIFY_API_KEY` and `SHOPIFY_API_SECRET` are correct on the server.
3. In `src/index.jsx`, set `API_BASE_URL` to your deployed Laravel domain.
4. Ensure app has order read scopes (`read_orders`) and reauthorize install if scopes changed.

## Deploy (from app root)

Use Shopify CLI app commands to include and publish this extension.

- `shopify app dev` for local iterative testing
- `shopify app deploy` to deploy extension updates

Then enable the extension block in customer account customization (if prompted by Shopify admin).
