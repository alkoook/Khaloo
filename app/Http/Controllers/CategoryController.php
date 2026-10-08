<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\TelegramService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ], [
            'name.required' => 'الاسم مطلوب.',
        ]);

        $category = Category::create(['name' => trim($request->input('name', ''))]);
        app(TelegramService::class)->notifyCreated('category', $category->name);

        return redirect()->route('categories.index')->with('success', 'تم إضافة التصنيف.');
    }
}
