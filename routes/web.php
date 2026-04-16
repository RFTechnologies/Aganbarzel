<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TagController;
use App\Http\Controllers\RalController;
use App\Http\Controllers\Storefront\AppProxyOrderProgressController;

use Illuminate\Support\Facades\Auth;

use App\Models\Tag;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/helpers',function(){
    $tag = Tag::where('id',25)->first();
    $now = new DateTime('now');
    $date =new DateTime($tag->delete_date);
    $diff = $date->diff($now);
    $condition = $now > $date;
    return dd($now,$date, $condition);
});

Route::get('/', function () {
    return view('welcome');
})->middleware(['verify.shopify'])->name('home');
Route::get('/login',function(){
    return redirect()->route('home');
})->middleware(['verify.shopify'])->name('login');
Route::get('/products/count',[ProductController::class, 'count'])->middleware(['verify.shopify'])->name('count');
Route::get('/products/delete', [ProductController::class, 'delete'])->middleware(['verify.shopify'])->name('delete');
Route::get('/products/getProducts',[ProductController::class, 'getProducts'])->middleware(['verify.shopify'])->name('products.get');

// Tags
Route::get('/tags',[TagController::class, 'index'])->name('tags');
Route::post('tags/add',[TagController::class, 'add'])->middleware(['verify.shopify'])->name('tags.add');
Route::get('/tags/delete/{id}',[TagController::class, 'delete'])->name('tags.delete');
Route::get('tags/deleteProducts/{id}',[TagController::class,  'deleteProducts'])->middleware(['verify.shopify'])->name('tags.deleteproducts');
Route::post('tags/updateDate',[TagController::class, 'updateDate'])->name('tags.updatedate');

// RALs
Route::get('/rals',[RalController::class, 'index'])->name('rals');
Route::post('/rals/delete',[RalController::class, 'delete'])->name('rals.delete');
Route::post('rals/add',[RalController::class, 'add'])->middleware(['verify.shopify'])->name('rals.add');

/*
|--------------------------------------------------------------------------
| Shopify App Proxy — storefront order progress (customer account)
|--------------------------------------------------------------------------
|
| Configure in Shopify Partner: App proxy URL -> https://YOUR_APP_DOMAIN/proxy/order-progress
| Subpath prefix must match theme fetch: /apps/{prefix}/order-progress
|
*/
Route::get('/proxy/order-progress', [AppProxyOrderProgressController::class, 'show'])
    ->name('storefront.app-proxy.order-progress');