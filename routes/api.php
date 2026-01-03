<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\WishlistController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\VendorProfileController;
use App\Http\Controllers\VendorOrderController;
use App\Http\Controllers\SettlementController;
use App\Http\Controllers\AccountSettingController;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use App\Mail\VendorWelcomeEmail;
use App\Mail\OtpEmail;
use App\Models\Product;
use App\Models\Category;
use App\Mail\ProductListingApprovalEmail;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ShowcaseSetController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/


Route::get('/status', function () {
    return response()->json(['status' => 'ok']);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);

// Password Reset Routes
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/verify-otp', [AuthController::class, 'verifyOtp']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

// Social Login Routes
Route::get('/auth/{provider}/redirect', [\App\Http\Controllers\SocialAuthController::class, 'redirect']);
Route::get('/auth/{provider}/callback', [\App\Http\Controllers\SocialAuthController::class, 'callback']);

// Public product discovery (no auth) — still protected by app-signature middleware (see Kernel)
Route::get('/products', [ProductController::class, 'index'])->name('products.index');
Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');
Route::get('/products/{product}/related', [ProductController::class, 'related'])->name('products.related');

// Public showcase set discovery (no auth)
Route::get('/showcase-sets', [ShowcaseSetController::class, 'index'])->name('showcase-sets.index');
Route::get('/showcase-sets/{showcaseSet}', [ShowcaseSetController::class, 'show'])->name('showcase-sets.show');


// Public showcase set discovery (no auth)
Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');


// Public Product Routes for discovery
Route::get('/products/search', [ProductController::class, 'search'])->name('products.search');
Route::get('/products/tags', [ProductController::class, 'findByTags'])->name('products.findByTags');
Route::get('/products/category/{category}', [ProductController::class, 'findByCategory'])->name('products.findByCategory');
Route::get('/users/{user}/products', [ProductController::class, 'findByUser'])->name('products.findByUser');

// Public Cart Routes (Authenticated user detected via Code or Header if present)
Route::post('/cart', [CartController::class, 'store'])->name('cart.store');
Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
Route::patch('/cart/{product}', [CartController::class, 'update'])->name('cart.update');
Route::delete('/cart/{product}', [CartController::class, 'destroy'])->name('cart.destroy');

// Public Wishlist Routes
Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
Route::post('/wishlist/{product}', [WishlistController::class, 'store'])->name('wishlist.store');
Route::delete('/wishlist/{product}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

// Public Order Routes (Authenticated or Guest)
Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
Route::post('/orders/verify-payment', [OrderController::class, 'verifyPayment'])->name('orders.verify-payment');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Admin Routes
    Route::middleware(['role:admin'])->prefix('admin')->group(function () {
        Route::apiResource('users', \App\Http\Controllers\AdminUserController::class);

        // Admin Vendor Management
        Route::get('/vendors', [\App\Http\Controllers\AdminVendorController::class, 'index'])->name('admin.vendors.index');
        Route::post('/vendors', [\App\Http\Controllers\AdminVendorController::class, 'store'])->name('admin.vendors.store');
        Route::get('/vendors/{vendorProfile}', [\App\Http\Controllers\AdminVendorController::class, 'show'])->name('admin.vendors.show');
        Route::put('/vendors/{vendorProfile}', [\App\Http\Controllers\AdminVendorController::class, 'update'])->name('admin.vendors.update');
        Route::get('/banks', [\App\Http\Controllers\AdminVendorController::class, 'getBanks'])->name('admin.banks.index');
        Route::get('/resolve-account', [\App\Http\Controllers\AdminVendorController::class, 'resolveAccount'])->name('admin.resolve-account');

        // Admin Product Management
        Route::get('/products', [\App\Http\Controllers\ProductController::class, 'adminIndex'])->name('admin.products.index');
        Route::get('/products/status', [\App\Http\Controllers\ProductController::class, 'getProductsByStatus'])->name('admin.products.by-status');

        // Admin Category Management
        Route::get('/categories', [\App\Http\Controllers\CategoryController::class, 'adminIndex'])->name('admin.categories.index');

        // Admin Dashboard Stats
        Route::get('/stats', [\App\Http\Controllers\AdminDashboardController::class, 'index'])->name('admin.stats.index');

        Route::get('/carts', [CartController::class, 'index'])->name('carts.index');
        Route::get('/carts', [CartController::class, 'index'])->name('carts.index');

        Route::get('/transactions', [TransactionController::class, 'adminIndex'])->name('admin.transactions.index');
        Route::get('/settlements', [SettlementController::class, 'adminIndex'])->name('admin.settlements.index');


        // Admin Order Routes
        Route::get('/orders', [OrderController::class, 'adminIndex'])->name('admin.orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'adminShow'])->name('admin.orders.show');
    });

    // Product Management Routes
    Route::apiResource('products', ProductController::class)->except(['index', 'show']);
    Route::post('/products/bulk-action', [ProductController::class, 'bulkAction'])->name('products.bulk-action');
    Route::patch('/products/{product}/status', [ProductController::class, 'updateStatus'])->name('products.update-status');
    Route::patch('/products/{product}/stock', [ProductController::class, 'updateStock']);

    // Routes for the authenticated vendor to manage their own products
    Route::get('/vendor/products', [ProductController::class, 'myProducts'])->name('vendor.products.index');
    Route::get('/vendor/products/archived', [ProductController::class, 'archived'])->name('vendor.products.archived');



    // Order Routes
    // Order Routes (Moved to public area)

    // Transaction Routes
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::patch('/transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');

    // Vendor Profile Routes
    Route::get('/vendors', [VendorProfileController::class, 'index'])->name('vendors.index');
    Route::post('/vendor-profiles', [VendorProfileController::class, 'store'])->name('vendor-profiles.store');
    Route::get('/vendor-profiles/{vendorProfile}', [VendorProfileController::class, 'show'])->name('vendor-profiles.show');
    Route::put('/vendor-profiles/{vendorProfile}', [VendorProfileController::class, 'update'])->name('vendor-profiles.update');

    // Vendor Order Routes
    Route::get('/vendor/orders', [VendorOrderController::class, 'index'])->name('vendor.orders.index');
    Route::get('/vendor/orders/{order}', [VendorOrderController::class, 'show'])->name('vendor.orders.show');
    Route::patch('/vendor/orders/{order}', [VendorOrderController::class, 'update'])->name('vendor.orders.update');

    // Settlement Routes
    Route::get('/settlements', [SettlementController::class, 'index'])->name('settlements.index');
    Route::patch('/settlements/{settlement}', [SettlementController::class, 'update'])->name('settlements.update');

    // Account Settings Routes
    Route::get('/account-settings', [AccountSettingController::class, 'show'])->name('account-settings.show');
    Route::put('/account-settings', [AccountSettingController::class, 'update'])->name('account-settings.update');
    Route::put('/account-settings/password', [AccountSettingController::class, 'updatePassword'])->name('account-settings.password');



    // Category Routes
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::post('/categories/{category}/products/{product}', [CategoryController::class, 'addProduct']);
    Route::delete('/categories/{category}/products/{product}', [CategoryController::class, 'removeProduct']);
    Route::get('/categories/{category}/products-not-in', [CategoryController::class, 'getProductsNotInCategory']);

    // Showcase Set Routes
    Route::apiResource('showcase-sets', ShowcaseSetController::class)->except(['index', 'show']);
    Route::post('/showcase-sets/{showcaseSet}/products/{product}', [ShowcaseSetController::class, 'addProduct']);
    Route::delete('/showcase-sets/{showcaseSet}/products/{product}', [ShowcaseSetController::class, 'removeProduct']);
    Route::get('/showcase-sets/{showcaseSet}/products-not-in', [ShowcaseSetController::class, 'getProductsNotInSet']);

    // Temporary route to update user role and send vendor welcome email
    Route::put('/update-role/{user}', function (Request $request, User $user) {
        $user->update(['role' => 'vendor']);
        Mail::to($user->email)->send(new App\Mail\VendorWelcomeEmail($user));
        return response()->json(['message' => 'User role updated and vendor welcome email sent.']);
    })->name('user.update-role');

    // Temporary route to send OTP email
    Route::post('/send-otp/{user}', function (Request $request, User $user) {
        $otp = rand(100000, 999999); // Generate a 6-digit OTP
        $reason = $request->input('reason', 'account verification'); // Default reason
        Mail::to($user->email)->send(new App\Mail\OtpEmail($otp, $reason));
        return response()->json(['message' => 'OTP email sent.']);
    })->name('user.send-otp');

    // Temporary route to update product approval status and send email - MOVED to ProductController@updateStatus
    // Deleted legacy temporary route
});
