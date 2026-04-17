<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RalController;
use App\Http\Controllers\Storefront\CustomerAccountOrderProgressController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});

Route::post('/products/create', [ProductController::class, 'create']);

Route::get('/rals',[RalController::class, 'getAll']);


//Testing

Route::get('/testing',function(){
    return 'is working';
});

Route::post('/products/getvariant', [ProductController::class, 'getVariant']);
Route::post('/products/updatevariant',[ProductController::class, 'updateVariant']);

/*
|--------------------------------------------------------------------------
| New Customer Accounts endpoint
|--------------------------------------------------------------------------
|
| Customer account UI extensions can call this endpoint with a Shopify
| session token (Bearer JWT). It reuses the same checklist logic as the
| classic App Proxy flow.
|
*/
Route::get('/customer-account/order-progress', [CustomerAccountOrderProgressController::class, 'show'])
    ->name('storefront.customer-account.order-progress');