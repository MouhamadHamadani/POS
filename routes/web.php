<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(auth()->check() ? '/pos' : '/login');
});

Route::middleware(['auth'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // Shifts
    Route::get('/shifts/open', [ShiftController::class, 'showOpen'])->name('shifts.open');
    Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open.store');
    Route::get('/shifts/close', [ShiftController::class, 'showClose'])->name('shifts.close');
    Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close.store');

    // POS — requires open shift
    Route::middleware('shift')->group(function () {
        Route::get('/pos', [PosController::class, 'sell'])->name('pos.sell');

        // AJAX endpoints from POS Alpine UI
        Route::get('/pos/api/products', [PosController::class, 'products'])->name('pos.api.products');
        Route::get('/pos/api/barcode', [PosController::class, 'lookupBarcode'])->name('pos.api.barcode');
        Route::post('/pos/api/sales', [SaleController::class, 'store'])->name('pos.api.sales');
        Route::get('/pos/api/sales/{sale}', [SaleController::class, 'show'])->name('pos.api.sales.show');
    });

    // Products & Categories — admin, manager, stock keeper
    Route::middleware('role:admin,manager,stock')->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('products.adjust-stock');

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });
    Route::middleware('role:admin,manager')->group(function () {
        Route::view('/reports', 'reports.index')->name('reports.index');
        Route::view('/customers', 'customers.index')->name('customers.index');
        Route::view('/suppliers', 'suppliers.index')->name('suppliers.index');
        Route::view('/purchase-orders', 'purchases.index')->name('purchases.index');
    });
    Route::middleware('role:admin')->group(function () {
        Route::view('/settings', 'settings.index')->name('settings.index');
        Route::view('/users', 'users.index')->name('users.index');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/pin', [ProfileController::class, 'updatePin'])->name('profile.pin');
});

require __DIR__.'/auth.php';
