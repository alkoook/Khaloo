<div class="module-page">
    <div class="page-heading"><div><span class="eyebrow">بطاقة صنف</span><h1>إضافة منتج</h1><p>أنشئ البيانات الأساسية، ثم أدخل المخزون والتكلفة من فاتورة شراء حقيقية.</p></div></div>
    <section class="workspace-card">
        <form wire:submit="save" class="form-grid">
            <label>اسم المنتج *<input type="text" wire:model="name" required>@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>التصنيف *<select wire:model="category_id" required><option value="">اختر التصنيف</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('category_id')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>الشركة / المورّد التعريفي<select wire:model="partner_id"><option value="">غير محدد</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}">{{ $supplier->name }}</option>@endforeach</select>@error('partner_id')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>بلد المنشأ<input type="text" wire:model="country_origin"></label>
            <label>حد تنبيه المخزون<input type="number" min="0" wire:model="alert_stock_at">@error('alert_stock_at')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label>صورة المنتج<input type="file" wire:model="image" accept="image/*">@error('image')<small class="field-error">{{ $message }}</small>@enderror</label>
            <label class="full">الوصف<textarea wire:model="description" rows="3"></textarea></label>
            <div class="full notice-card">سيبدأ المخزون بصفر، وستبقى تكلفة الشراء وسعر البيع غير محددين حتى تسجيل حركة فعلية.</div>
            <div class="full row-actions"><button class="primary-button" type="submit" wire:loading.attr="disabled">حفظ بطاقة المنتج</button><a class="ghost-button" href="{{ route('products.index') }}" wire:navigate>إلغاء</a></div>
        </form>
    </section>
</div>
