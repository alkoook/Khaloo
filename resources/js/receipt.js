import { downloadElementAsPng } from './document-screenshot';

window.captureReceipt = async (button) => {
    const receipt = document.querySelector('.receipt');

    if (! receipt || button?.disabled) {
        return;
    }

    const label = button?.querySelector('[data-screenshot-label]');
    const originalLabel = label?.textContent;

    if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    }

    if (label) {
        label.textContent = 'جارٍ إنشاء الصورة...';
    }

    try {
        document.body.classList.add('is-capturing-receipt');

        await downloadElementAsPng(receipt, button?.dataset.filename || 'invoice.png');
    } catch (error) {
        console.error('Receipt screenshot failed.', error);
        window.alert('تعذر إنشاء لقطة الفاتورة. يرجى المحاولة مرة أخرى.');
    } finally {
        document.body.classList.remove('is-capturing-receipt');

        if (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }

        if (label) {
            label.textContent = originalLabel;
        }
    }
};

const sentCutTokens = new Set();

window.requestReceiptCut = async (printToken) => {
    const { autoCut, cutUrl } = document.body.dataset;

    if (autoCut !== '1' || ! cutUrl || ! printToken || sentCutTokens.has(printToken)) {
        return;
    }

    sentCutTokens.add(printToken);

    try {
        const response = await fetch(cutUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ print_token: printToken }),
        });

        if (! response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || 'Receipt cut request failed.');
        }
    } catch (error) {
        console.error('Receipt cutter failed.', error);
    }
};
