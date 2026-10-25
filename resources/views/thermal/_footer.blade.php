@if($thermal['show_footer'] || ($thermal['show_address'] && filled($thermal['company_address'])))
    <footer class="thermal-footer">
        @if($thermal['show_footer'] && filled($thermal['footer']))<span>{{ $thermal['footer'] }}</span>@endif
        @if($thermal['show_address'] && filled($thermal['company_address']))<span>{{ $thermal['company_address'] }}</span>@endif
    </footer>
@endif
<div class="thermal-end-feed" aria-hidden="true"></div>
