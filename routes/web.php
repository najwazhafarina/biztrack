<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ReportController;

/*
|--------------------------------------------------------------------------
| Web Routes - BizTrack UMKM
|--------------------------------------------------------------------------
*/

// Auth routes
Route::get('/', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login.post');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Protected routes
Route::middleware(['auth.biztrack'])->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Products & Inventory (Owner + Cashier)
    Route::resource('products', ProductController::class);
    Route::get('/inventory/log', [ProductController::class, 'inventoryLog'])->name('inventory.log');

    // POS / Sales
    Route::get('/pos', [SaleController::class, 'pos'])->name('pos.index');
    Route::post('/pos/checkout', [SaleController::class, 'checkout'])->name('pos.checkout');
    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [SaleController::class, 'show'])->name('sales.show');
    Route::get('/sales/{sale}/receipt', [SaleController::class, 'receipt'])->name('sales.receipt');

    // Accounting (Owner only)
    Route::middleware(['role.owner'])->group(function () {
        Route::get('/accounting/coa', [AccountingController::class, 'coa'])->name('accounting.coa');
        Route::get('/accounting/journal', [AccountingController::class, 'journal'])->name('accounting.journal');
        Route::get('/accounting/ledger', [AccountingController::class, 'ledger'])->name('accounting.ledger');

        // Expenses
        Route::resource('expenses', ExpenseController::class);

        // Reports
        Route::get('/reports/sales', [ReportController::class, 'sales'])->name('reports.sales');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
        Route::get('/reports/financial', [ReportController::class, 'financial'])->name('reports.financial');
    });
});
