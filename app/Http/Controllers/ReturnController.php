<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Invoice;
use App\Services\ReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReturnController extends Controller
{
    public function cancel(Request $request, Invoice $invoice, ReturnService $returns): RedirectResponse
    {
        abort_unless($invoice->isReturn(), 404);
        abort_unless($request->user()?->can('delete', $invoice), 403);
        $validated = $request->validate([
            'cancellation_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'cancellation_reason.required' => 'اكتب سبب إلغاء المرتجع.',
            'cancellation_reason.min' => 'سبب إلغاء المرتجع يجب أن يكون واضحًا.',
            'cancellation_reason.max' => 'سبب إلغاء المرتجع طويل جدًا.',
        ]);

        try {
            $returns->cancelSalesReturn($invoice, $validated['cancellation_reason']);
        } catch (BusinessRuleException $exception) {
            return back()->withErrors(['cancellation_reason' => $exception->getMessage()]);
        }

        return redirect()->route('invoices.returns.index')->with('success', 'تم إلغاء المرتجع وعكس أثره المالي والمخزني بنجاح.');
    }
}
