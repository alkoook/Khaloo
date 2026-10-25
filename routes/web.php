<?php

use App\Http\Controllers\AccountStatementController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseBackupController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\ReturnController;
use App\Http\Controllers\TelegramController;
use App\Http\Middleware\EnsureWeeklyBackupReminder;
use App\Livewire\FinancialStatus;
use App\Livewire\Invoices\Returns\Create;
use App\Livewire\MessageToYou;
use App\Livewire\Payments\Register;
use App\Livewire\Profile\Edit;
use App\Livewire\Settings\Index;
use App\Models\Category;
use App\Models\InAppNotification;
use App\Models\Partner;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login')->name('login.store');
});

Route::post('/logout', LogoutController::class)->name('logout')->middleware('auth');

Route::middleware(['auth', EnsureWeeklyBackupReminder::class])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/message-to-you', MessageToYou::class)->name('message-to-you');

    Route::get('/profile', Edit::class)->name('profile.edit');
    Route::get('/admin/settings', Index::class)->name('settings.index');
    Route::post('/admin/settings/database-backup', DatabaseBackupController::class)->name('settings.database-backup');

    Route::view('/categories', 'categories.index')->name('categories.index');
    Route::view('/categories/create', 'categories.create')->name('categories.create');
    Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}/edit', fn (Category $category) => view('categories.edit', ['category' => $category]))->name('categories.edit');

    Route::get('/products', App\Livewire\Products\Index::class)->name('products.index');
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
    Route::post('/invoices/purchases/{invoice}/cancel', [InvoiceController::class, 'cancelPurchase'])->name('invoices.purchases.cancel');

    Route::get('/invoices/returns', App\Livewire\Invoices\Returns\Index::class)->name('invoices.returns.index');
    Route::get('/invoices/returns/create/{invoice?}', Create::class)->name('invoices.returns.create');
    Route::get('/invoices/returns/{invoice}/export', [ReturnController::class, 'exportWord'])->name('invoices.returns.export');

    Route::get('/invoices/{invoice}/edit', App\Livewire\Invoices\Edit::class)->name('invoices.edit');
    Route::get('/invoices/{invoice}/receipt', [InvoiceController::class, 'receipt'])->name('invoices.receipt');
    Route::post('/invoices/{invoice}/receipt/cut', [InvoiceController::class, 'cutReceipt'])->name('invoices.receipt.cut');

    Route::get('/ledger', App\Livewire\Ledger\Index::class)->name('ledger.index');
    Route::get('/ledger/statements/{partner}', [AccountStatementController::class, 'show'])->name('statements.show');
    Route::get('/ledger/statements/{partner}/thermal', [AccountStatementController::class, 'thermal'])->name('statements.thermal');
    Route::post('/ledger/statements/{partner}/telegram', [AccountStatementController::class, 'telegram'])->name('statements.telegram');
    Route::get('/financial-status', FinancialStatus::class)->name('financial-status.index');
    Route::get('/expenses', App\Livewire\Expenses\Index::class)->name('expenses.index');
    Route::get('/payments/register', Register::class)->name('payments.register');
    Route::get('/quotations', App\Livewire\Quotations\Index::class)->name('quotations.index');
    Route::get('/quotations/{quotation}/print', [QuotationController::class, 'print'])->name('quotations.print');
    Route::get('/quotations/{quotation}/thermal', [QuotationController::class, 'thermal'])->name('quotations.thermal');
    Route::get('/quotations/{quotation}/word', [QuotationController::class, 'word'])->name('quotations.word');
    Route::post('/quotations/{quotation}/telegram', [QuotationController::class, 'telegram'])->name('quotations.telegram');
    Route::get('/notifications', App\Livewire\Notifications\Index::class)->name('notifications.index');
    Route::post('/notifications/backup-reminder/{notification}/dismiss', function (InAppNotification $notification) {
        abort_unless($notification->type === 'backup_reminder', 404);
        $notification->markRead();

        return back();
    })->name('notifications.backup-dismiss');
    Route::post('/notifications/read-all', function () {
        InAppNotification::query()->whereNull('read_at')->update(['read_at' => now()]);

        return back();
    })->name('notifications.read-all');
    Route::get('/reports', App\Livewire\Reports\Index::class)->name('reports.index');
    Route::get('/reports/exports/{export}', ReportExportController::class)->name('reports.exports.download');

    Route::post('/telegram/send-invoice', [TelegramController::class, 'sendInvoice'])->name('telegram.send-invoice');
    Route::post('/telegram/send-ledger', [TelegramController::class, 'sendLedger'])->name('telegram.send-ledger');
});
