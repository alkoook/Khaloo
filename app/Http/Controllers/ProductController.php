<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->get('search', '');
        $categoryFilter = $request->get('category', '');

        $products = Product::query()
            ->with('category')
            ->when($search, fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
            ->when($categoryFilter, fn ($q) => $q->where('category_id', $categoryFilter))
            ->latest()
            ->paginate(10)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => Category::orderBy('name')->get(),
            'search' => $search,
            'categoryFilter' => $categoryFilter,
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['id' => ['required', 'exists:products,id']]);
        $product = Product::findOrFail($request->input('id'));
        $name = $product->name ?? 'منتج #'.$product->id;
        $product->delete();
        app(TelegramService::class)->notifyDeleted('product', $name);

        return redirect()->route('products.index')->with('success', 'تم حذف المنتج.');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'name' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'purchase_price' => ['nullable', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['required', 'integer', 'min:0'],
            'country_origin' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:10240'],
        ]);

        $data = [
            'category_id' => $request->input('category_id'),
            'name' => $request->input('name') ?: null,
            'description' => $request->input('description') ?: null,
            'purchase_price' => $request->filled('purchase_price') ? $request->input('purchase_price') : null,
            'sale_price' => $request->filled('sale_price') ? $request->input('sale_price') : null,
            'quantity' => (int) $request->input('quantity', 0),
            'country_origin' => $request->input('country_origin') ?: null,
        ];

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($data);

        app(TelegramService::class)->notifyCreated('product', $product->name ?? 'منتج #'.$product->id);

        return redirect()->route('products.index')->with('success', 'تم إضافة المنتج.');
    }
}
