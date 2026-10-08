const preview = document.querySelector('.receipt-preview');

if (preview) {
    const allowedThemes = ['obsidian-ruby', 'arctic-cobalt', 'marine-night'];

    try {
        const storedTheme = localStorage.getItem('khaloo-theme');
        document.documentElement.dataset.previewTheme = allowedThemes.includes(storedTheme)
            ? storedTheme
            : (allowedThemes.includes(document.body.dataset.previewTheme) ? document.body.dataset.previewTheme : 'arctic-cobalt');
    } catch (_) {
        document.documentElement.dataset.previewTheme = 'arctic-cobalt';
    }

    if (! window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        let frame = 0;

        preview.addEventListener('pointermove', (event) => {
            if (frame) return;

            frame = requestAnimationFrame(() => {
                const bounds = preview.getBoundingClientRect();
                const x = ((event.clientX - bounds.left) / bounds.width) * 100;
                const y = ((event.clientY - bounds.top) / bounds.height) * 100;
                preview.style.setProperty('--pointer-x', `${Math.max(0, Math.min(100, x)).toFixed(2)}%`);
                preview.style.setProperty('--pointer-y', `${Math.max(0, Math.min(100, y)).toFixed(2)}%`);
                frame = 0;
            });
        }, { passive: true });
    }
}
