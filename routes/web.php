<?php

use App\Http\Controllers\ActivationController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HeldSaleController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PriceApprovalController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect(auth()->check() ? '/pos' : '/login');
});

// Language switch. Outside the auth group: the login and setup screens need it.
Route::get('/locale/{locale}', [LocaleController::class, 'switch'])
    ->whereIn('locale', ['en', 'ar'])->name('locale.switch');

// Trial-expired lock screen. Outside the auth group: the people it is for
// (cashiers, the client's admin) are signed out of nothing and in no position
// to sign in. See ActivationGate for what is still reachable while locked.
Route::get('/locked', [ActivationController::class, 'locked'])->name('locked');

// First-run provisioning. Self-closing: 404s once any account exists.
Route::get('/setup', [SetupController::class, 'show'])->name('setup.show');
Route::post('/setup', [SetupController::class, 'store'])->name('setup.store');

Route::middleware(['auth'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // Help -> About in the desktop menu bar points here. The build ships no
    // auto-updater, so "which version is this machine on" is a question
    // support has to be able to answer over the phone.
    Route::view('/about', 'about')->name('about');

    // Receipt reprint — accessible outside the shift gate so cashier can
    // print receipts for earlier sales after closing a shift.
    Route::get('/pos/receipts/{sale}/print', [ReceiptController::class, 'show'])->name('receipts.print');
    // A4 invoice straight to the printer chosen in Settings (desktop app only; the sell screen
    // falls back to the print dialog when this reports it did not print).
    Route::post('/pos/receipts/{sale}/print-a4', [ReceiptController::class, 'printA4'])->name('receipts.print-a4');

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
        Route::post('/pos/api/preview-change', [PosController::class, 'previewChange'])->name('pos.api.preview-change');
        // Attaching a customer at the till is the loyalty feature's front door.
        Route::middleware('feature:loyalty')->group(function () {
            Route::get('/pos/api/customers/search', [CustomerController::class, 'search'])->name('pos.api.customers.search');
            Route::post('/pos/api/customers/quick-add', [CustomerController::class, 'quickAdd'])->name('pos.api.customers.quick-add');
        });
        Route::post('/pos/api/sales', [SaleController::class, 'store'])->name('pos.api.sales');
        Route::post('/pos/api/price-approval', [PriceApprovalController::class, 'store'])->name('pos.api.price-approval');
        Route::get('/pos/api/sales/{sale}', [SaleController::class, 'show'])->name('pos.api.sales.show');

        // Hold / recall (in-flight carts that survive serving other customers)
        Route::get('/pos/api/holds', [HeldSaleController::class, 'index'])->name('pos.api.holds.index');
        Route::post('/pos/api/holds', [HeldSaleController::class, 'store'])->name('pos.api.holds.store');
        Route::post('/pos/api/holds/{heldSale}/recall', [HeldSaleController::class, 'recall'])->name('pos.api.holds.recall');
        Route::delete('/pos/api/holds/{heldSale}', [HeldSaleController::class, 'destroy'])->name('pos.api.holds.destroy');
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
        Route::get('/products/check-barcode', [ProductController::class, 'checkBarcode'])->name('products.check-barcode');

        // Stock movements: the read side of inventory_adjustments.
        Route::get('/products/movements', [StockMovementController::class, 'index'])->name('products.movements');
        Route::get('/products/movements/export', [StockMovementController::class, 'export'])
            ->middleware('demo:blocked')->name('products.movements.export');

        // Bulk upload. `can:` resolves User::canBulkUploadProducts(): eligible
        // role AND a super-admin having switched that role's toggle on. Declared
        // before /products/{product} routes so 'import' can't be read as an id.
        // `demo:blocked` — a demo build's catalogue is a fixed baseline.
        Route::middleware(['can:bulk-upload-products', 'demo:blocked'])->group(function () {
            Route::get('/products/import', [ProductImportController::class, 'show'])->name('products.import.show');
            Route::get('/products/import/template', [ProductImportController::class, 'template'])->name('products.import.template');
            Route::post('/products/import/preview', [ProductImportController::class, 'preview'])->name('products.import.preview');
            Route::post('/products/import', [ProductImportController::class, 'store'])->name('products.import.store');
        });

        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        // Suppliers & Purchase Orders — one feature area, gated whole.
        Route::middleware('feature:suppliers')->group(function () {
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
    });

    // Returns — cashiers can initiate (pending), managers/admins auto-approve.
    Route::middleware('feature:returns')->group(function () {
        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::get('/returns/search', [ReturnController::class, 'searchSale'])->name('returns.search');
        Route::post('/returns/find-sale', [ReturnController::class, 'findSale'])->name('returns.find-sale');
        Route::get('/returns/create/{sale}', [ReturnController::class, 'create'])->name('returns.create');
        Route::post('/returns/sale/{sale}', [ReturnController::class, 'store'])->name('returns.store');
        Route::get('/returns/{return}', [ReturnController::class, 'show'])->name('returns.show');
        Route::middleware('role:admin,manager')->group(function () {
            Route::post('/returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
            Route::post('/returns/{return}/reject', [ReturnController::class, 'reject'])->name('returns.reject');
        });
    });

    // Customers + Reports — admin/manager
    Route::middleware('role:admin,manager')->group(function () {
        Route::middleware('feature:loyalty')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
            Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
            Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
            Route::post('/customers/{customer}/payment', [CustomerController::class, 'recordPayment'])->name('customers.payment');
        });

        // Expenses: fixed and running costs, optionally paid out of the open shift's drawer.
        Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::get('/expenses/export', [ExpenseController::class, 'export'])->middleware('demo:blocked')->name('expenses.export');
        Route::get('/expenses/create', [ExpenseController::class, 'create'])->name('expenses.create');
        Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
        Route::get('/expenses/duplicate-fixed', [ExpenseController::class, 'duplicateForm'])->name('expenses.duplicate');
        Route::post('/expenses/duplicate-fixed', [ExpenseController::class, 'duplicateStore'])->name('expenses.duplicate.store');
        Route::get('/expenses/{expense}/edit', [ExpenseController::class, 'edit'])->name('expenses.edit');
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');

        // The index and the daily summary ship on every tier; the three that
        // expose cost, valuation and margin are Standard+.
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/sales/daily', [ReportController::class, 'dailySales'])->name('reports.daily-sales');
        Route::get('/reports/sales/by-product', [ReportController::class, 'salesByProduct'])
            ->middleware('feature:reports.sales_by_product')->name('reports.sales-by-product');
        // Cashier price overrides: an audit of prices changed at the till, so it ships on every tier.
        Route::get('/reports/sales/price-overrides', [ReportController::class, 'priceOverrides'])->name('reports.price-overrides');
        Route::get('/reports/inventory/stock-levels', [ReportController::class, 'stockLevels'])
            ->middleware('feature:reports.stock_levels')->name('reports.stock-levels');
        // Shows value at cost and retail like stock-levels does, so it ships with it.
        Route::get('/reports/inventory/by-date', [ReportController::class, 'inventoryByDate'])
            ->middleware('feature:reports.stock_levels')->name('reports.inventory-by-date');
        // Expenses ship with the Expenses module on every tier; only the P&L that nets them off is Standard+.
        Route::get('/reports/financial/expenses', [ReportController::class, 'expenses'])->name('reports.expenses');
        Route::get('/reports/financial/pnl', [ReportController::class, 'profitLoss'])
            ->middleware('feature:reports.profit_loss')->name('reports.pnl');
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
        // Settings > Expenses: the category list.
        Route::post('/settings/expense-categories', [ExpenseCategoryController::class, 'store'])->name('settings.expense-categories.store');
        Route::put('/settings/expense-categories/{category}', [ExpenseCategoryController::class, 'update'])->name('settings.expense-categories.update');
        Route::delete('/settings/expense-categories/{category}', [ExpenseCategoryController::class, 'destroy'])->name('settings.expense-categories.destroy');

        Route::post('/settings/receipt/test-print', [SettingController::class, 'testPrintReceipt'])->name('settings.receipt.test-print');

        // Premium's receipt differentiator. Starter/Standard keep the
        // header/footer/paper-width fields on the Receipt tab and nothing more.
        Route::post('/settings/receipt-template', [SettingController::class, 'updateReceiptTemplate'])
            ->middleware('feature:receipts.custom_templates')->name('settings.receipt-template.update');

        // Tax rates are part of the demo baseline — editing them mid-pitch would
        // undermine the "always resets clean" property, and VAT is one of the
        // things being demonstrated.
        Route::middleware('demo:blocked')->group(function () {
            Route::post('/settings/tax', [SettingController::class, 'storeTax'])->name('settings.tax.store');
            Route::put('/settings/tax/{tax}', [SettingController::class, 'updateTax'])->name('settings.tax.update');
            Route::delete('/settings/tax/{tax}', [SettingController::class, 'destroyTax'])->name('settings.tax.destroy');
        });
    });

    // Demo builds only — restore the seeded baseline mid-pitch. `demo:only`
    // means a production install does not have this endpoint at all.
    Route::post('/demo/reset', [DemoController::class, 'reset'])
        ->middleware('demo:only')->name('demo.reset');

    // Super-admin only — backups are vendor/owner territory, not the client's.
    // A demo build seeds no super_admin, so this group is already unreachable
    // there; `demo:blocked` says so out loud rather than relying on that.
    Route::middleware(['role:super_admin', 'demo:blocked'])->group(function () {
        // Deleted products, read-only. Sits here and not with the other product
        // routes on purpose: the client's own admin must never see a trash of
        // deleted products, so the gate is route middleware, not a controller if.
        Route::get('/products/trashed', [ProductController::class, 'trashed'])->name('products.trashed');

        Route::post('/settings/permissions', [SettingController::class, 'updatePermissions'])->name('settings.permissions.update');

        // The Plan tab holds two independent controls, each with its own route:
        //  - tier: which features and seat limits this install is licensed for.
        //    Vendor territory — the client's own admin must never be able to
        //    raise their own plan.
        //  - activation: unlock (activate) or lock by hand (deactivate).
        //    ActivationGate lets these routes and the tier route (plus the tab
        //    that hosts them) through while locked.
        // Neither control touches the other's setting. Both sit in this group's
        // demo:blocked, so a demo build refuses them server-side.
        Route::post('/settings/plan', [SettingController::class, 'updatePlan'])->name('settings.plan.update');
        Route::post('/settings/activation', [ActivationController::class, 'activate'])->name('settings.activation.activate');
        Route::post('/settings/activation/deactivate', [ActivationController::class, 'deactivate'])->name('settings.activation.deactivate');

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
