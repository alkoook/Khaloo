<div class="message-stage">
    <div class="message-ambient ambient-one"></div><div class="message-ambient ambient-two"></div>
    <section class="message-card" wire:key="message-{{ $sequence }}">
        <div class="message-mark">{{ ($message['type'] ?? '') === 'ayah' ? '۞' : (($message['type'] ?? '') === 'dua' ? 'آمين' : '✦') }}</div>
        <span class="eyebrow">رسالة إليك في هذه اللحظة</span>
        <blockquote>{{ $message['text'] ?? '' }}</blockquote>
        @if(isset($message['source']))<p>سورة {{ $message['source'] }} @if(isset($message['number']))· {{ $message['number'] }}@endif</p>@elseif(isset($message['author']))<p>{{ $message['author'] }}</p>@else<p>دعاء</p>@endif
        <button type="button" class="message-button" wire:click="another" wire:loading.attr="disabled"><span wire:loading.remove>رسالة أخرى</span><span wire:loading>لحظة…</span><i>↻</i></button>
    </section>
    <a href="{{ route('dashboard') }}" wire:navigate class="dashboard-gateway">الانتقال إلى لوحة الأعمال ←</a>
</div>
