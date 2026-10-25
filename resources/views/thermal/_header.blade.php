<header class="thermal-brand">
    @if($thermal['show_logo'] && filled($thermal['logo']))
        <img class="thermal-brand__logo" src="{{ Storage::url($thermal['logo']) }}" alt="شعار {{ $thermal['company_name'] }}">
    @endif
    @if($thermal['show_company'])
        <h1 class="thermal-brand__name">{{ $thermal['company_name'] }}</h1>
    @endif
    @if($thermal['show_phone'] && $thermal['company_phones'] !== [])
        <div class="thermal-brand__phones">
            @foreach($thermal['company_phones'] as $phone)<span>{{ $phone }}</span>@endforeach
        </div>
    @endif
    <div class="thermal-document-title">{{ $documentTitle }}</div>
</header>
