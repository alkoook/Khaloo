@extends('layouts.app')

@section('title', 'إضافة زبون/مورد')

@section('content')
<div class="rounded-xl p-6 max-w-2xl" style="background: var(--app-card); border: 1px solid var(--app-border);">
    <h2 class="text-xl font-semibold mb-4" style="color: var(--app-primary);">إضافة زبون / مورد</h2>
    <form action="{{ route('partners.store') }}" method="POST" enctype="multipart/form-data" class="space-y-4">
        @csrf
        <div>
            <label for="type" class="block text-sm font-medium mb-1">النوع *</label>
            <select id="type" name="type" class="rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
                <option value="customer" {{ old('type', 'customer') === 'customer' ? 'selected' : '' }}>زبون</option>
                <option value="supplier" {{ old('type') === 'supplier' ? 'selected' : '' }}>مورد</option>
            </select>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="name" class="block text-sm font-medium mb-1">الاسم</label>
                <input type="text" id="name" name="name" value="{{ old('name') }}"
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
            <div>
                <label for="father_name" class="block text-sm font-medium mb-1">اسم الأب</label>
                <input type="text" id="father_name" name="father_name" value="{{ old('father_name') }}"
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="nickname" class="block text-sm font-medium mb-1">الكنية</label>
                <input type="text" id="nickname" name="nickname" value="{{ old('nickname') }}"
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
            <div>
                <label for="title" class="block text-sm font-medium mb-1">اللقب</label>
                <input type="text" id="title" name="title" value="{{ old('title') }}"
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="mother_name" class="block text-sm font-medium mb-1">اسم الأم</label>
                <input type="text" id="mother_name" name="mother_name" value="{{ old('mother_name') }}"
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
            <div>
                <label for="national_id" class="block text-sm font-medium mb-1">الرقم الوطني</label>
                <input type="text" id="national_id" name="national_id" value="{{ old('national_id') }}"
                    class="w-full rounded-lg border px-4 py-2" style="background: var(--app-bg); border-color: var(--app-border); color: var(--app-text);">
            </div>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">الصورة</label>
            <input type="file" name="image" accept="image/*" class="w-full text-sm" style="color: var(--app-text);">
            @error('image')
                <p class="text-sm text-red-500 mt-1">{{ $message }}</p>
            @enderror
        </div>
        <div class="flex gap-2">
            <button type="submit" class="px-4 py-2 rounded-lg text-white font-medium" style="background: var(--app-primary);">حفظ</button>
            <a href="{{ route('partners.index') }}" class="px-4 py-2 rounded-lg" style="background: var(--app-bg); color: var(--app-text);">إلغاء</a>
        </div>
    </form>
</div>
@endsection
