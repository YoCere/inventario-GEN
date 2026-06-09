<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\KardexController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\FinancialStatementController;
use App\Http\Controllers\TelegramWebhookController;

// Telegram webhook (no auth required, token validated internally)
Route::post('/telegram/webhook', [TelegramWebhookController::class, 'handle']);

Route::middleware(['auth', 'verified'])->group(function () {
    // =========================================================================
    // Dashboard & Profile
    // =========================================================================
    Route::get('/', function () {
        return redirect()->route('dashboard');
    });
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::view('profile', 'profile.index')->name('profile.index');

    // =========================================================================
    // Master Data
    // =========================================================================
    Route::prefix('master')->group(function () {
        Route::view('customers', 'customers.index')->name('customers.index');
        Route::view('suppliers', 'suppliers.index')->name('suppliers.index');
        Route::view('categories', 'categories.index')->name('categories.index');
        Route::view('units', 'units.index')->name('units.index');
        Route::view('products', 'products.index')->name('products.index');
        Route::view('products/report', 'products.report')->name('products.report');
        Route::get('products/report/print', [\App\Http\Controllers\ProductReportController::class, 'print'])->name('products.report.print');
        Route::view('warehouses', 'warehouses.index')->name('warehouses.index');
        Route::view('locations', 'locations.index')->name('locations.index');
        Route::view('transfers', 'transfers.index')->name('transfers.index');
        Route::get('kardex', [KardexController::class, 'index'])->name('products.kardex.index');
        Route::get('kardex/print', [KardexController::class, 'print'])->name('products.kardex.print');
    });

    // =========================================================================
    // Transactions
    // =========================================================================

    // Purchases
    Route::resource('purchases', PurchaseController::class);
    Route::prefix('purchases/{purchase}')->name('purchases.')->controller(PurchaseController::class)->group(function () {
        Route::patch('ordered', 'markOrdered')->name('mark-ordered');
        Route::patch('received', 'markReceived')->name('mark-received');
        Route::patch('paid', 'markPaid')->name('mark-paid');
        Route::patch('cancel', 'cancel')->name('cancel');
        Route::patch('restore-draft', 'restoreToDraft')->name('restore-draft');
    });

    // Sales
    Route::resource('sales', SalesController::class)->except(['edit', 'update']);
    Route::prefix('sales/{sale}')->name('sales.')->controller(SalesController::class)->group(function () {
        Route::get('print', 'print')->name('print');
        Route::patch('complete', 'complete')->name('complete');
        Route::patch('restore', 'restore')->name('restore');
    });

    // =========================================================================
    // Finance
    // =========================================================================
    // =========================================================================
    // Reports
    // =========================================================================
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::view('stock-by-location', 'reports.stock-by-location')->name('stock-by-location');
    });

    Route::prefix('finance')->name('finance.')->group(function () {
        Route::view('/', 'finance.index')->name('index');
        Route::view('categories', 'finance-categories.index')->name('categories.index');
        Route::view('transactions', 'finance-transactions.index')->name('transactions.index');
        Route::view('chart-of-accounts', 'finance-chart-of-accounts.index')->name('chart-of-accounts.index');
        Route::view('journal-entries', 'finance-journal-entries.index')->name('journal-entries.index');
        Route::get('journal-entries/book', [\App\Http\Controllers\JournalBookController::class, 'index'])->name('journal-entries.book');
        Route::get('journal-entries/book/print', [\App\Http\Controllers\JournalBookController::class, 'print'])->name('journal-entries.book.print');
        Route::permanentRedirect('kardex', 'master/kardex')->name('kardex.legacy-redirect');
        Route::get('statements', [FinancialStatementController::class, 'index'])->name('statements.index');
        Route::get('transactions/print/{printId}', [FinanceReportController::class, 'print'])->name('transactions.print');
    });

    Route::prefix('finance')->name('finance.')->middleware('admin')->group(function () {
        Route::view('accounting-periods', 'finance-accounting-periods.index')->name('accounting-periods.index');
        Route::view('journal-entries/create', 'finance-journal-entries.create')->name('journal-entries.create');
        Route::view('trial-balance', 'accounting.trial-balance')->name('trial-balance');
        Route::view('worksheet', 'accounting.worksheet')->name('worksheet');
        Route::view('asset-categories', 'asset-categories.index')->name('asset-categories.index');
        Route::view('fixed-assets', 'fixed-assets.index')->name('fixed-assets.index');
        Route::view('fixed-assets/{assetId}/schedule', 'fixed-assets.schedule')->name('fixed-assets.schedule');

        Route::view('loans', 'loans.index')->name('loans.index');
        Route::view('loans/{loan}/schedule', 'loans.schedule')->name('loans.schedule');

        Route::view('budgets', 'budgets.index')->name('budgets.index');
        Route::view('budgets/{budget}/show', 'budgets.show')->name('budgets.show');

        Route::view('boms', 'boms.index')->name('boms.index');
        Route::view('production', 'production.index')->name('production.index');

        Route::permanentRedirect('payroll', 'users/payroll')->name('payroll.legacy-redirect');
    });

    // =========================================================================
    // Settings & Users - Solo Admin
    // =========================================================================
    Route::middleware('admin')->group(function () {
        Route::view('users', 'users.index')->name('users.index');
        Route::get('users/payroll', [PayrollController::class, 'index'])->name('users.payroll.index');
        Route::get('users/payroll/create', [PayrollController::class, 'create'])->name('users.payroll.create');
        Route::post('users/payroll', [PayrollController::class, 'store'])->name('users.payroll.store');
        Route::get('users/payroll/{sheet}', [PayrollController::class, 'show'])->name('users.payroll.show');
        Route::post('users/payroll/{sheet}/post', [PayrollController::class, 'post'])->name('users.payroll.post');
        Route::get('users/payroll/{sheet}/print', [PayrollController::class, 'print'])->name('users.payroll.print');
        Route::view('settings', 'settings.index')->name('settings.index');
    });

    // Roles y permisos — solo Developer (gate adicional dentro del componente).
    Route::middleware('developer')->group(function () {
        Route::view('roles', 'roles.index')->name('roles.index');
        Route::get('settings/backups', function () {
            abort_if(! auth()->user()?->isDeveloper(), 403);
            return view('settings.backups');
        })->name('settings.backups');
    });

    // =========================================================================
    // Internal APIs (AJAX)
    // =========================================================================
    Route::prefix('ajax')->name('ajax.')->group(function () {
        Route::post('products', [\App\Http\Controllers\Api\ProductController::class, 'search'])->name('products.search');
        Route::post('suppliers', [\App\Http\Controllers\Api\SupplierController::class, 'search'])->name('suppliers.search');
        Route::post('customers', [\App\Http\Controllers\Api\CustomerController::class, 'search'])->name('customers.search');
        Route::post('customers/store', [\App\Http\Controllers\Api\CustomerController::class, 'store'])->name('customers.store');
        Route::post('categories', [\App\Http\Controllers\Api\CategoryController::class, 'search'])->name('categories.search');
        Route::post('units', [\App\Http\Controllers\Api\UnitController::class, 'search'])->name('units.search');
        Route::post('users', [\App\Http\Controllers\Api\UserController::class, 'search'])->name('users.search');
        Route::post('finance-categories', [\App\Http\Controllers\Api\FinanceCategoryController::class, 'search'])->name('finance-categories.search');
    });
});

require __DIR__.'/auth.php';
