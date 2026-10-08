@php
    $scrollTo ??= 'body';
    $scroll = $scrollTo === false ? '' : "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView({behavior:'smooth'})";
@endphp
@if($paginator->hasPages())
<nav class="khaloo-pagination" role="navigation" aria-label="التنقل بين الصفحات">
    <p class="pagination-summary">عرض {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} من أصل {{ $paginator->total() }} نتيجة</p>
    <div class="pagination-pages">
        <button type="button" class="pagination-button" wire:click="previousPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scroll }}" @disabled($paginator->onFirstPage()) aria-label="الصفحة السابقة">السابق</button>
        @foreach($elements as $element)
            @if(is_string($element))<span class="pagination-page" aria-hidden="true">{{ $element }}</span>@endif
            @if(is_array($element)) @foreach($element as $page=>$url)
                <button type="button" wire:key="page-{{ $paginator->getPageName() }}-{{ $page }}" class="pagination-page {{ $page===$paginator->currentPage()?'is-active':'' }}" wire:click="gotoPage({{ $page }},'{{ $paginator->getPageName() }}')" x-on:click="{{ $scroll }}" @disabled($page===$paginator->currentPage()) aria-label="الصفحة {{ $page }}">{{ $page }}</button>
            @endforeach @endif
        @endforeach
        <button type="button" class="pagination-button" wire:click="nextPage('{{ $paginator->getPageName() }}')" x-on:click="{{ $scroll }}" @disabled(!$paginator->hasMorePages()) aria-label="الصفحة التالية">التالي</button>
    </div>
</nav>
@endif
