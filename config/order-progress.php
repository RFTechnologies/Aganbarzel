<?php

/*
|--------------------------------------------------------------------------
| Order progress (legacy)
|--------------------------------------------------------------------------
|
| The storefront + customer-account order progress API now returns only
| tags from each Shopify order (`order_tags`). This file is kept so
| `php artisan config:cache` does not fail if something still merges it;
| no keys are required for the current behavior.
|
*/

return [];
