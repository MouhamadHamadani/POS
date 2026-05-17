<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
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
        Route::get('/pos/api/products', [PosController::class, 'products'])->name('pos.api.products');
        Route::get('/pos/api/barcode', [PosController::class, 'lookupBarcode'])->name('pos.api.barcode');
        Route::get('/pos/api/customers/search', [CustomerController::class, 'search'])->name('pos.api.customers.search');
        Route::post('/pos/api/customers/quick-add', [CustomerController::class, 'quickAdd'])->name('pos.api.customers.quick-add');
        Route::post('/pos/api/sales', [SaleController::class, 'store'])->name('pos.api.sales');
        Route::get('/pos/api/sales/{sale}', [SaleController::class, 'show'])->name('pos.api.sales.show');
    });

    // Products & Categories — admin/manager/stock
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

        // Suppliers & Purchase Orders
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::get('/suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->name('suppliers.edit');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::delete('/suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        Route::post('/suppliers/{supplier}/payment', [SupplierController::class, 'recordPayment'])->name('suppliers.payment');

        Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])->name('purchases.index');
        Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])->name('purchases.create');
        Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->name('purchases.store');
        Route::get('/purchase-orders/{purchase}', [PurchaseOrderController::class, 'show'])->name('purchases.show');
        Route::get('/purchase-orders/{purchase}/edit', [PurchaseOrderController::class, 'edit'])->name('purchases.edit');
        Route::put('/purchase-orders/{purchase}', [PurchaseOrderController::class, 'update'])->name('purchases.update');
        Route::post('/purchase-orders/{purchase}/transition', [PurchaseOrderController::class, 'transition'])->name('purchases.transition');
        Route::post('/purchase-orders/{purchase}/receive', [PurchaseOrderController::class, 'receive'])->name('purchases.receive');
    });

    // Customers + Reports — admin/manager
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        Route::post('/customers/{customer}/payment', [CustomerController::class, 'recordPayment'])->name('customers.payment');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/sales/daily', [ReportController::class, 'dailySales'])->name('reports.daily-sales');
        Route::get('/reports/sales/by-product', [ReportController::class, 'salesByProduct'])->name('reports.sales-by-product');
        Route::get('/reports/inventory/stock-levels', [ReportController::class, 'stockLevels'])->name('reports.stock-levels');
        Route::get('/reports/financial/pnl', [ReportController::class, 'profitLoss'])->name('reports.pnl');
    });

    // Admin-only — Users, Settings
    Route::middleware('role:admin')->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle', [UserController::class, 'toggleActive'])->name('users.toggle');
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('/users/{user}/reset-pin', [UserController::class, 'resetPin'])->name('users.reset-pin');

        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/tax', [SettingController::class, 'storeTax'])->name('settings.tax.store');
        Route::put('/settings/tax/{tax}', [SettingController::class, 'updateTax'])->name('settings.tax.update');
        Route::delete('/settings/tax/{tax}', [SettingController::class, 'destroyTax'])->name('settings.tax.destroy');
        Route::post('/settings/backup/now', [SettingController::class, 'backupNow'])->name('settings.backup.now');
        Route::get('/settings/backup/download/{filename}', [SettingController::class, 'backupDownload'])->name('settings.backup.download');
        Route::post('/settings/backup/restore/{filename}', [SettingController::class, 'backupRestore'])->name('settings.backup.restore');
        Route::delete('/settings/backup/{filename}', [SettingController::class, 'backupDelete'])->name('settings.backup.delete');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/pin', [ProfileController::class, 'updatePin'])->name('profile.pin');
});

require __DIR__.'/auth.php';
