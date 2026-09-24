<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PartnerController;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Product;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::post('/logout', LogoutController::class)->name('logout')->middleware('auth');

Route::middleware(['auth', \App\Http\Middleware\EnsureWeeklyBackupReminder::class])->group(function (): void {
    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index'])->name('dashboard');
    Route::get('/message-to-you', \App\Livewire\MessageToYou::class)->name('message-to-you');

    Route::get('/profile', \App\Livewire\Profile\Edit::class)->name('profile.edit');
    Route::get('/admin/settings', \App\Livewire\Settings\Index::class)->name('settings.index');
    Route::post('/admin/settings/database-backup', \App\Http\Controllers\DatabaseBackupController::class)->name('settings.database-backup');

    Route::view('/categories', 'categories.index')->name('categories.index');
    Route::view('/categories/create', 'categories.create')->name('categories.create');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}/edit', fn (Category $category) => view('categories.edit', ['category' => $category]))->name('categories.edit');

    Route::get('/products', \App\Livewire\Products\Index::class)->name('products.index');
    Route::view('/products/create', 'products.create')->name('products.create');
    Route::get('/products/{product}/edit', fn (Product $product) => view('products.edit', ['product' => $product]))->name('products.edit');

    Route::view('/partners', 'partners.index')->name('partners.index');
    Route::post('/partners/delete', [PartnerController::class, 'destroy'])->name('partners.destroy');
    Route::view('/partners/create', 'partners.create')->name('partners.create');
    Route::post('/partners', [PartnerController::class, 'store'])->name('partners.store');
    Route::get('/partners/{partner}/edit', fn (Partner $partner) => view('partners.edit', ['partner' => $partner]))->name('partners.edit');

    Route::view('/invoices/sales', 'invoices.sales.index')->name('invoices.sales.index');
    Route::get('/invoices/sales/create', [InvoiceController::class, 'createSales'])->name('invoices.sales.create');
    Route::get('/invoices/sales/{invoice}', [InvoiceController::class, 'show'])->name('invoices.sales.show');
    Route::view('/invoices/purchases', 'invoices.purchases.index')->name('invoices.purchases.index');
    Route::get('/invoices/purchases/create', [InvoiceController::class, 'createPurchases'])->name('invoices.purchases.create');
    Route::get('/invoices/purchases/{invoice}', [InvoiceController::class, 'show'])->name('invoices.purchases.show');

    Route::get('/invoices/returns', \App\Livewire\Invoices\Returns\Index::class)->name('invoices.returns.index');
    Route::get('/invoices/returns/create/{invoice?}', \App\Livewire\Invoices\Returns\Create::class)->name('invoices.returns.create');
    Route::get('/invoices/returns/{invoice}/export', [\App\Http\Controllers\ReturnController::class, 'exportWord'])->name('invoices.returns.export');

    Route::get('/invoices/{invoice}/edit', \App\Livewire\Invoices\Edit::class)->name('invoices.edit');
    Route::get('/invoices/{invoice}/receipt', [InvoiceController::class, 'receipt'])->name('invoices.receipt');

    Route::get('/ledger', \App\Livewire\Ledger\Index::class)->name('ledger.index');
    Route::get('/expenses', \App\Livewire\Expenses\Index::class)->name('expenses.index');
    Route::get('/payments/register', \App\Livewire\Payments\Register::class)->name('payments.register');
    Route::get('/quotations', \App\Livewire\Quotations\Index::class)->name('quotations.index');
    Route::get('/quotations/{quotation}/print', [\App\Http\Controllers\QuotationController::class, 'print'])->name('quotations.print');
    Route::get('/notifications', \App\Livewire\Notifications\Index::class)->name('notifications.index');
    Route::post('/notifications/read-all', function () {
        \App\Models\InAppNotification::query()->whereNull('read_at')->update(['read_at' => now()]);
        return back();
    })->name('notifications.read-all');
    Route::get('/reports', \App\Livewire\Reports\Index::class)->name('reports.index');
    Route::get('/reports/exports/{export}', \App\Http\Controllers\ReportExportController::class)->name('reports.exports.download');

    Route::post('/telegram/send-invoice', [\App\Http\Controllers\TelegramController::class, 'sendInvoice'])->name('telegram.send-invoice');
    Route::post('/telegram/send-ledger', [\App\Http\Controllers\TelegramController::class, 'sendLedger'])->name('telegram.send-ledger');
});
