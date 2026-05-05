<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        'tags/add',
        'tags/updateDate',
        'rals/add',
        'rals/delete',
        'webhooks/shopify/orders-updated',
    ];
}
