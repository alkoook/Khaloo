<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->get('search', '');
        $typeFilter = $request->get('type', '');

        $partners = Partner::query()
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($typeFilter, fn ($q) => $q->where('type', $typeFilter))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('partners.index', [
            'partners' => $partners,
            'search' => $search,
            'typeFilter' => $typeFilter,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['id' => ['required', 'exists:partners,id']]);
        $partner = Partner::withCount(['invoices', 'settlements'])->findOrFail($request->input('id'));
        if ($partner->invoices_count > 0 || $partner->settlements_count > 0) {
            return back()->with('error', 'لا يمكن حذف الطرف لوجود سجل مالي مرتبط به.');
        }
        $name = $partner->name ?? (($partner->type === 'customer' ? 'السيد' : 'مورد').' #'.$partner->id);
        $partner->delete();
        app(TelegramService::class)->notifyDeleted('partner', $name);

        return redirect()->route('partners.index')->with('success', 'تم الحذف.');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'father_name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'type' => ['required', 'in:customer,supplier'],
            'image' => ['nullable', 'image', 'max:10240'],
        ]);

        $data = [
            'name' => $request->input('name') ?: null,
            'father_name' => $request->input('father_name') ?: null,
            'nickname' => $request->input('nickname') ?: null,
            'title' => $request->input('title') ?: null,
            'mother_name' => $request->input('mother_name') ?: null,
            'national_id' => $request->input('national_id') ?: null,
            'phone' => $request->input('phone') ?: null,
            'type' => $request->input('type'),
        ];

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('partners', 'public');
        }

        $partner = Partner::create($data);

        app(TelegramService::class)->notifyCreated('partner', $partner->name ?? ($partner->type === 'customer' ? 'السيد' : 'مورد').' #'.$partner->id);

        return redirect()->route('partners.index')->with('success', 'تم الإضافة.');
    }
}
