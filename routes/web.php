<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(auth()->check() ? '/pos' : '/login');
});

Route::middleware(['auth'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // POS sales screen — requires an open shift
    Route::middleware('shift')->group(function () {
        Route::view('/pos', 'pos.sell')->name('pos.sell');
    });

    // Shift management
    Route::view('/shifts/open', 'shifts.open')->name('shifts.open');
    Route::view('/shifts/close', 'shifts.close')->name('shifts.close');

    // Module group routes — stubs to be filled in by controllers later
    Route::middleware('role:admin,manager,stock')->group(function () {
        Route::view('/products', 'products.index')->name('products.index');
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
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
