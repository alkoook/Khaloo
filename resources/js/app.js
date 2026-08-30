import './bootstrap';

const themes = ['obsidian-ruby', 'arctic-cobalt', 'marine-night'];
const fonts = ['cairo', 'changa', 'reem-kufi', 'baloo'];
const read = (key, fallback, allowed) => {
    try { const value = localStorage.getItem(key); return allowed.includes(value) ? value : fallback; }
    catch { return fallback; }
};
const appearance = window.KhalooAppearance = {
    apply(defaultTheme = 'marine-night', defaultFont = 'cairo') {
        const theme = read('khaloo-theme', themes.includes(defaultTheme) ? defaultTheme : themes[0], themes);
        const font = read('khaloo-font', fonts.includes(defaultFont) ? defaultFont : fonts[0], fonts);
        document.documentElement.dataset.theme = theme;
        document.documentElement.dataset.font = font;
        return { theme, font };
    },
    set(theme, font) {
        if (themes.includes(theme)) localStorage.setItem('khaloo-theme', theme);
        if (fonts.includes(font)) localStorage.setItem('khaloo-font', font);
        return this.apply(theme, font);
    },
};

appearance.apply(document.documentElement.dataset.serverTheme, document.documentElement.dataset.serverFont);
const restoreAppearance = () => appearance.apply(document.documentElement.dataset.serverTheme, document.documentElement.dataset.serverFont);
document.addEventListener('livewire:navigating', restoreAppearance);
document.addEventListener('livewire:navigated', restoreAppearance);
window.addEventListener('pageshow', restoreAppearance);
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook('morph.updated', restoreAppearance);
});

window.khalooShell = (defaultTheme, defaultFont) => ({
    collapsed: localStorage.getItem('khaloo-sidebar-collapsed') === '1', mobileOpen: false,
    confirmDialog: null, notificationsOpen: false, ...appearance.apply(defaultTheme, defaultFont),
    toggleSidebar() { this.collapsed = !this.collapsed; localStorage.setItem('khaloo-sidebar-collapsed', this.collapsed ? '1' : '0'); },
    setAppearance(theme = this.theme, font = this.font) { Object.assign(this, appearance.set(theme, font)); },
});

window.toastCenter = () => ({
    toasts: [], show(message, type = 'success') { if (!message) return; const id = Date.now() + Math.random(); this.toasts.push({ id, message, type }); setTimeout(() => this.remove(id), 4200); },
    remove(id) { this.toasts = this.toasts.filter((toast) => toast.id !== id); },
});

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-confirm]'); if (!button) return;
    if (button.dataset.confirmed === '1') { delete button.dataset.confirmed; return; }
    event.preventDefault(); event.stopImmediatePropagation();
    window.dispatchEvent(new CustomEvent('khaloo-confirm', { detail: { message: button.getAttribute('data-confirm'), accept: () => { button.dataset.confirmed = '1'; button.click(); } } }));
}, true);
